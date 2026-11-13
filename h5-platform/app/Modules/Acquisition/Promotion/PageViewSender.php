<?php

namespace App\Modules\Acquisition\Promotion;

use App\Modules\Acquisition\Analytics\ClickContext;
use App\Modules\Acquisition\Analytics\Clock;
use App\Modules\Acquisition\Probe\MetaPageTest;
use App\Modules\Acquisition\Registration\LegacyAdvertisingBridge;
use App\Modules\Acquisition\Sending\Credentials;
use App\Modules\Acquisition\Sending\MetaAdapter;
use App\Support\RuntimeEnvironmentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** No financial table, dispatch queue or auto-retry. Exact event or bounded dedicated ledger only. */
final class PageViewSender
{
    private function origin(): string
    {
        return app(RuntimeEnvironmentGuard::class)->controlledAcquisitionTestOrigin();
    }

    private function gate(object $row): ?string
    {
        $this->origin();
        $source = DB::table('acquisition_sources')->where('id', $row->source_id)->first();
        $privacy = DB::table('acquisition_privacy')->where('id', $row->privacy_id)->first();
        if (! $privacy || $privacy->advertising !== 'granted' || app(LegacyAdvertisingBridge::class)->markerDenied($privacy)
            || ! CarbonImmutable::parse($privacy->updated_at)->equalTo(CarbonImmutable::parse($row->privacy_updated_at))
            || ! $source || $source->is_simulation || $source->revoked_at || $source->consent !== 'granted'
            || $source->privacy_id !== $privacy->id || $source->landing_key !== $row->landing_key
            || $source->link_id !== $row->link_id || $source->pixel_id !== $row->pixel_id || (int) $source->pixel_version !== (int) $row->pixel_version
            || $source->channel !== 'meta') {
            return 'CONSENT_OR_SOURCE_INVALID';
        }
        if (CarbonImmutable::parse($row->event_at)->greaterThan(now()) || CarbonImmutable::parse($row->expires_at)->lessThanOrEqualTo(now())
            || CarbonImmutable::parse($source->expires_at)->lessThanOrEqualTo(now())) {
            return 'PROMOTION_EVENT_EXPIRED';
        }
        $link = DB::table('acquisition_links')->where('id', $row->link_id)->first();
        $pixel = DB::table('acquisition_pixels')->where('id', $row->pixel_id)->first();
        $version = $this->version($row);
        if (! $pixel || ! $pixel->active || $pixel->environment !== 'test' || $pixel->platform !== 'meta' || ! $version
            || $version->environment !== 'test' || $version->platform !== 'meta' || $version->external_id !== $pixel->external_id
            || ! $link || ! $link->active || $link->drama_id !== $row->drama_id || $link->pixel_id !== $row->pixel_id || $link->platform !== 'meta') {
            return 'PROMOTION_TEST_ASSET_OR_LINK_INVALID';
        }

        return null;
    }

    private function version(object $row): ?object
    {
        return DB::table('acquisition_pixel_versions')->where('pixel_id', $row->pixel_id)->where('version', $row->pixel_version)->first();
    }

    public function send(string $id): array
    {
        try {
            $this->origin();
        } catch (Throwable) {
            return ['state' => 'blocked', 'reason' => 'STAGING_TEST_RUNTIME_INVALID'];
        }
        if (config('acquisition.transport') !== 'http') {
            return ['state' => 'blocked', 'reason' => 'EXTERNAL_DISABLED'];
        }
        if (! Str::isUuid($id) || DB::transactionLevel() !== 0) {
            return ['state' => 'blocked', 'reason' => 'PROMOTION_INPUT_INVALID'];
        }
        $row = DB::transaction(function () use ($id) {
            $row = DB::table('acquisition_promotion_pageviews')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->state !== 'pending') {
                return null;
            }
            DB::table('acquisition_promotion_pageviews')->where('id', $id)->update(['state' => 'attempted', 'attempted_at' => Clock::stamp()]);

            return $row;
        });
        if (! $row) {
            return ['state' => 'blocked', 'reason' => 'PROMOTION_ALREADY_CONSUMED_OR_MISSING'];
        }
        $result = ['state' => 'blocked', 'reason' => 'PROMOTION_CONTEXT_INVALID'];
        try {
            if ($reason = $this->gate($row)) {
                $result['reason'] = $reason;
            } else {
                $context = json_decode(Crypt::decryptString($row->context_encrypted), true, flags: JSON_THROW_ON_ERROR);
                if (($context['page_url'] ?? null) === $this->origin().'/dramas/'.$row->drama_id
                    && filter_var($context['ip'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                    && is_string($context['user_agent'] ?? null) && strlen($context['user_agent']) > 0 && strlen($context['user_agent']) <= 512
                    && ! preg_match('/[\x00-\x1f\x7f]/', $context['user_agent'])) {
                    $version = $this->version($row);
                    $asset = (object) ['pixel_id' => $row->pixel_id, 'pixel_version' => (int) $row->pixel_version,
                        'platform' => 'meta', 'external_id' => $version->external_id, 'environment' => 'test'];
                    $credential = app(Credentials::class)->forDelivery($asset);
                    if (! $credential || empty($credential['test_event_code'])) {
                        $result['reason'] = 'CREDENTIALS_REQUIRED';
                    } else {
                        $payload = app(MetaPageTest::class)->payload($row->id, $row->event_at, $context, $credential['test_event_code']);
                        $payload['data'][0]['event_id'] = 'h5_promotion_'.$row->id;
                        $source = DB::table('acquisition_sources')->where('id', $row->source_id)->first();
                        $matching = json_decode(Crypt::decryptString($source->context_encrypted), true, flags: JSON_THROW_ON_ERROR);
                        $matching = app(ClickContext::class)->forPlatform('meta', array_intersect_key($matching, array_flip(['fbc', 'fbp'])));
                        $payload['data'][0]['user_data'] += $matching;
                        if ($reason = $this->gate($row)) {
                            $result['reason'] = $reason;
                        } else {
                            $response = app(MetaAdapter::class)->send($asset, $payload, $credential['token']);
                            $result = ['state' => $response->state === 'api_accepted' ? 'api_accepted' : ($response->state === 'blocked' ? 'blocked' : 'unconfirmed'), 'reason' => $response->state === 'api_accepted' ? null : ($response->state === 'blocked' ? $response->error : 'PROMOTION_API_NOT_CONFIRMED_NO_RETRY')];
                        }
                    }
                }
            }
        } catch (Throwable) {
            $result = ['state' => 'unconfirmed', 'reason' => 'PROMOTION_RESULT_UNKNOWN_NO_RETRY'];
        }
        DB::table('acquisition_promotion_pageviews')->where('id', $id)->where('state', 'attempted')->update($result + ['context_encrypted' => null]);

        return $result;
    }

    public function run(int $limit): array
    {
        $this->origin();
        if (config('acquisition.transport') !== 'http') {
            return ['EXTERNAL_DISABLED' => 1];
        }
        $ids = DB::table('acquisition_promotion_pageviews')->where('state', 'pending')->orderBy('created_at')->limit(max(1, min(10, $limit)))->pluck('id');
        $results = [];
        foreach ($ids as $id) {
            $state = $this->send($id)['state'];
            $results[$state] = ($results[$state] ?? 0) + 1;
        }

        return $results;
    }
}
