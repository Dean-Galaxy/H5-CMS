<?php

namespace App\Modules\Acquisition\Services;

use App\Contracts\CheckoutAttribution;
use App\Data\AttributionSnapshot;
use App\Data\CheckoutPageContext;
use App\Modules\Acquisition\Analytics\ClickContext;
use App\Modules\Acquisition\Analytics\Clock;
use App\Modules\Acquisition\Analytics\TrackingContext;
use App\Modules\Acquisition\Registration\LegacyAdvertisingBridge;
use App\Support\RuntimeEnvironmentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Private purchase evidence only. No account mapping and no changes to landing attribution. */
final class CheckoutContextStore implements CheckoutAttribution
{
    public function capture(AttributionSnapshot $source, CheckoutPageContext $page): ?AttributionSnapshot
    {
        try {
            $tracking = app(TrackingContext::class);
            if (! $tracking->enabled() || $source->checkout_context_id !== null || ! $this->requestAllowed()) {
                return null;
            }
            $rendered = CarbonImmutable::parse($page->rendered_at);
            if ($page->path !== '/membership' || ! preg_match('/T.+(?:Z|[+-]\d\d:\d\d)$/D', $page->rendered_at)
                || $rendered->greaterThan(now()) || $rendered->lessThan(now()->subMinutes(30))) {
                return null;
            }

            // Savepoint: unavailable storage must never poison a surrounding purchase transaction.
            return DB::transaction(function () use ($source, $rendered, $tracking) {
                $current = app(AttributionRecorder::class)->snapshot(null);
                if (! $current || ! self::sameSource($source->toArray(), $current->toArray())) {
                    return null;
                }
                $privacy = $tracking->privacy();
                $privacy = $privacy ? DB::table('acquisition_privacy')->where('id', $privacy->id)->lockForUpdate()->first() : null;
                $row = DB::table('acquisition_sources')->where('id', $source->source_id)->lockForUpdate()->first();
                if (! $privacy || $privacy->advertising !== 'granted' || app(LegacyAdvertisingBridge::class)->denied($privacy)
                    || ! $row || $row->privacy_id !== $privacy->id || $row->revoked_at || $row->consent !== 'granted'
                    || CarbonImmutable::parse($row->expires_at)->lessThanOrEqualTo(now()) || $row->is_simulation) {
                    return null;
                }
                $pixel = DB::table('acquisition_pixels')->where('id', $row->pixel_id)->first();
                $version = DB::table('acquisition_pixel_versions')->where('pixel_id', $row->pixel_id)->where('version', $row->pixel_version)->first();
                if (! $pixel || ! $pixel->active || ! $version || $version->platform !== $row->channel
                    || (TrackingContext::advertisingTestOnly() && $version->environment !== 'test')) {
                    return null;
                }
                $old = $row->context_encrypted ? json_decode(Crypt::decryptString($row->context_encrypted), true, flags: JSON_THROW_ON_ERROR) : [];
                $keys = $row->channel === 'meta' ? ['fbc', 'fbp'] : ['ttclid', 'ttp'];
                $matching = app(ClickContext::class)->forPlatform($row->channel, array_intersect_key($old, array_flip($keys)));
                $context = ['page_url' => $this->origin().'/membership', 'ip' => request()->server('REMOTE_ADDR'),
                    'user_agent' => request()->userAgent()] + $matching;
                if (! self::validContext($context)) {
                    return null;
                }
                $id = (string) Str::uuid();
                // Test phase evidence lives at most 24h, and never past the original source deadline.
                $until = now()->addDay()->min(CarbonImmutable::parse($row->expires_at))->min(CarbonImmutable::parse($row->captured_at)->addDays(90));
                DB::table('acquisition_checkout_contexts')->insert(['id' => $id, 'source_id' => $row->id, 'privacy_id' => $privacy->id,
                    'pixel_id' => $row->pixel_id, 'pixel_version' => $row->pixel_version, 'platform' => $row->channel,
                    'context_encrypted' => Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR)),
                    'rendered_at' => Clock::stamp($rendered), 'captured_at' => Clock::stamp(), 'expires_at' => Clock::stamp($until)]);

                return new AttributionSnapshot($source->source_id, $source->link_id, $source->channel, $source->pixel_config_version,
                    $source->captured_at, $source->consent, $id);
            });
        } catch (Throwable) {
            return null;
        }
    }

    private function origin(): string
    {
        if (! app()->environment('local', 'testing') || TrackingContext::advertisingTestOnly()) {
            return app(RuntimeEnvironmentGuard::class)->controlledAcquisitionTestOrigin();
        }

        return self::canonicalOrigin();
    }

    private function requestAllowed(): bool
    {
        $r = request();
        $origin = $this->origin();

        return $r->isMethod('POST') && $r->getPathInfo() === '/membership/checkout' && $r->isSecure()
            && $r->getSchemeAndHttpHost() === $origin && $r->header('Origin') === $origin
            && in_array($r->header('Sec-Fetch-Site'), [null, 'same-origin'], true)
            && ! $r->headers->has('Forwarded') && ! $r->headers->has('X-Forwarded-For')
            && ! $r->headers->has('X-Forwarded-Host') && ! $r->headers->has('X-Forwarded-Proto')
            && ! preg_match('/prefetch|prerender/i', $r->header('Purpose', '').' '.$r->header('Sec-Purpose', ''));
    }

    public static function sameSource(array $a, array $b): bool
    {
        foreach (['source_id', 'link_id', 'channel', 'pixel_config_version', 'consent'] as $key) {
            if (! isset($a[$key], $b[$key]) || $a[$key] !== $b[$key]) {
                return false;
            }
        }

        return isset($a['captured_at'], $b['captured_at']) && CarbonImmutable::parse($a['captured_at'])->equalTo(CarbonImmutable::parse($b['captured_at']));
    }

    /** An internal status accompanies whitelisted data; adapters only select their platform keys. */
    public function forDelivery(object $delivery, object $receipt, ?object $source): array
    {
        try {
            $snapshot = json_decode($receipt->attribution_snapshot ?? 'null', true, flags: JSON_THROW_ON_ERROR);
            $id = $snapshot['checkout_context_id'] ?? null;
            if (! is_string($id) || ! Str::isUuid($id)) {
                return ['_checkout_status' => 'CHECKOUT_CONTEXT_REQUIRED'];
            }
            $row = DB::table('acquisition_checkout_contexts')->where('id', $id)->first();
            if (! $row || ! $source || $row->source_id !== $source->id || $row->privacy_id !== $source->privacy_id
                || $row->pixel_id !== $delivery->pixel_id || (int) $row->pixel_version !== (int) $delivery->pixel_version
                || $row->platform !== $delivery->platform || $source->pixel_id !== $row->pixel_id
                || (int) $source->pixel_version !== (int) $row->pixel_version || $source->channel !== $row->platform
                || ! self::sameSource($snapshot, ['source_id' => $source->id, 'link_id' => $source->link_id, 'channel' => $source->channel,
                    'pixel_config_version' => (int) $source->pixel_version, 'consent' => $source->consent, 'captured_at' => $source->captured_at])) {
                return ['_checkout_status' => 'CHECKOUT_CONTEXT_INVALID'];
            }
            $version = DB::table('acquisition_pixel_versions')->where('pixel_id', $row->pixel_id)->where('version', $row->pixel_version)->first();
            if (! $version || $version->platform !== $delivery->platform || $version->external_id !== $delivery->external_id
                || $version->environment !== $delivery->environment || $source->link_id !== $delivery->link_id) {
                return ['_checkout_status' => 'CHECKOUT_CONTEXT_INVALID'];
            }
            if (CarbonImmutable::parse($row->expires_at)->lessThanOrEqualTo(now())) {
                return ['_checkout_status' => 'CHECKOUT_CONTEXT_EXPIRED'];
            }
            $data = json_decode(Crypt::decryptString($row->context_encrypted), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data) || ! self::validContext($data)) {
                return ['_checkout_status' => 'MATCH_CONTEXT_REQUIRED'];
            }

            return $data + ['_checkout_status' => 'valid'];
        } catch (Throwable) {
            return ['_checkout_status' => 'CHECKOUT_CONTEXT_INVALID'];
        }
    }

    private static function canonicalOrigin(): string
    {
        $url = config('app.url');
        if (! is_string($url) || preg_match('~\Ahttps://([a-zA-Z0-9.-]+)(?::443)?/?\z~D', $url, $match) !== 1) {
            throw new \LogicException('CHECKOUT_ORIGIN_INVALID');
        }

        return 'https://'.strtolower($match[1]);
    }

    public static function validContext(array $c): bool
    {
        $url = self::canonicalOrigin().'/membership';

        return ($c['page_url'] ?? null) === $url && str_starts_with($url, 'https://')
            && is_string($c['user_agent'] ?? null) && strlen($c['user_agent']) > 0 && strlen($c['user_agent']) <= 512
            && ! preg_match('/[\x00-\x1f\x7f]/', $c['user_agent'])
            && filter_var($c['ip'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
