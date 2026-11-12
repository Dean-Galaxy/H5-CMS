<?php

namespace App\Modules\Acquisition\Probe;

use App\Enums\Role;
use App\Modules\Acquisition\Analytics\Clock;
use App\Modules\Acquisition\Analytics\TrackingContext;
use App\Modules\Acquisition\Registration\LegacyAdvertisingBridge;
use App\Modules\Acquisition\Sending\Credentials;
use App\Modules\Acquisition\Sending\MetaAdapter;
use App\Modules\Acquisition\Services\AssetAccess;
use App\Support\ApiProblem;
use App\Support\RuntimeEnvironmentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Explicit Meta diagnostic only: never touches payment receipts, sources or the delivery queue. */
final class PageTestProbe
{
    public const PATH = '/admin/acquisition-page-test';

    public function origin(): string
    {
        try {
            return app(RuntimeEnvironmentGuard::class)->controlledAcquisitionTestOrigin();
        } catch (Throwable) {
            throw new ApiProblem('DEPENDENCY_NOT_READY', 503, '受控页面测试尚未就绪');
        }
    }

    public function owner(): string
    {
        $this->origin();
        $actor = app(AssetAccess::class)->actor();
        abort_unless($actor->role === Role::Owner, 403);

        return $actor->id;
    }

    public function pageProof(): void
    {
        $owner = $this->owner();
        $this->requestIsLocalPage();
        abort_unless(request()->isMethod('GET') && request()->getPathInfo() === self::PATH, 403);
        // No IP/UA or ad context is persisted before consent. Bind the rendered page to this staff session only.
        session()->put('acquisition.page_test.rendered', ['at' => Clock::stamp(), 'owner' => $owner]);
    }

    private function requestIsLocalPage(): void
    {
        $r = request();
        abort_unless($r->isSecure() && $r->getSchemeAndHttpHost() === $this->origin()
            && in_array($r->header('Sec-Fetch-Site'), [null, 'same-origin', 'none'], true)
            && ! preg_match('/prefetch|prerender/i', $r->header('Purpose', '').' '.$r->header('Sec-Purpose', '')), 403);
        foreach (['Forwarded', 'X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-Port'] as $header) {
            abort_if($r->headers->has($header), 403);
        }
    }

    public function create(string $pixelId, bool $agreed): string
    {
        $owner = $this->owner();
        $this->requestIsLocalPage();
        abort_unless(request()->isMethod('POST') && request()->getPathInfo() === self::PATH
            && request()->header('Origin') === $this->origin() && $agreed, 403);
        $proof = session()->pull('acquisition.page_test.rendered');
        abort_unless(is_array($proof) && ($proof['owner'] ?? null) === $owner && isset($proof['at']), 403);
        $at = CarbonImmutable::parse($proof['at']);
        abort_if($at->greaterThan(now()) || $at->addMinutes(10)->lessThanOrEqualTo(now()), 403);
        $ip = request()->server('REMOTE_ADDR');
        $ua = request()->userAgent();
        abort_unless(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && is_string($ua) && strlen($ua) > 0 && strlen($ua) <= 512 && ! preg_match('/[\x00-\x1f\x7f]/', $ua), 403);

        return DB::transaction(function () use ($pixelId, $at, $ip, $ua) {
            $pixel = app(AssetAccess::class)->get('pixel', $pixelId, true);
            abort_unless($this->assetValid($pixel), 403);
            $privacy = app(TrackingContext::class)->privacy();
            $privacy = $privacy ? DB::table('acquisition_privacy')->where('id', $privacy->id)->lockForUpdate()->first() : null;
            abort_unless($this->consented($privacy) && ! app(LegacyAdvertisingBridge::class)->denied($privacy), 403);
            $id = (string) Str::uuid();
            // Bind the grant revision without retaining a staff/viewer identity or creating a new consent scope.
            $context = ['page_url' => $this->origin().self::PATH, 'ip' => $ip, 'user_agent' => $ua,
                'privacy_updated_at' => $privacy->updated_at];
            DB::table('acquisition_page_tests')->insert(['id' => $id, 'pixel_id' => $pixel->id, 'pixel_version' => $pixel->version,
                'privacy_id' => $privacy->id, 'context_encrypted' => Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR)),
                'event_at' => Clock::stamp($at), 'expires_at' => Clock::stamp($at->addMinutes(10)), 'state' => 'pending', 'created_at' => Clock::stamp()]);
            session()->put('acquisition.page_test.intent', $id);

            return $id;
        });
    }

    private function assetValid(?object $pixel, ?int $version = null): bool
    {
        if (! $pixel || ! $pixel->active || $pixel->platform !== 'meta' || $pixel->environment !== 'test'
            || ($version !== null && (int) $pixel->version !== $version)) {
            return false;
        }
        $v = DB::table('acquisition_pixel_versions')->where('pixel_id', $pixel->id)->where('version', $pixel->version)->first();

        return $v && $v->platform === 'meta' && $v->environment === 'test' && $v->external_id === $pixel->external_id;
    }

    private function consented(?object $privacy): bool
    {
        return $privacy && $privacy->advertising === 'granted' && ! app(LegacyAdvertisingBridge::class)->markerDenied($privacy);
    }

    /** Host operator consumes exactly one UUID. Claim is committed before I/O; even uncertainty cannot be retried. */
    public function send(string $id): array
    {
        $this->origin();
        if (! Str::isUuid($id) || DB::transactionLevel() !== 0) {
            return ['state' => 'blocked', 'reason' => 'PROBE_INPUT_INVALID'];
        }
        $claim = DB::transaction(function () use ($id) {
            $row = DB::table('acquisition_page_tests')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->state !== 'pending') {
                return null;
            }
            DB::table('acquisition_page_tests')->where('id', $id)->update(['state' => 'attempted', 'attempted_at' => Clock::stamp()]);

            return $row;
        });
        if (! $claim) {
            return ['state' => 'blocked', 'reason' => 'PROBE_ALREADY_CONSUMED_OR_MISSING'];
        }
        $result = ['state' => 'blocked', 'reason' => 'PROBE_CONTEXT_INVALID'];
        try {
            $this->origin();
            $pixel = DB::table('acquisition_pixels')->where('id', $claim->pixel_id)->first();
            $privacy = DB::table('acquisition_privacy')->where('id', $claim->privacy_id)->first();
            $context = json_decode(Crypt::decryptString($claim->context_encrypted), true, flags: JSON_THROW_ON_ERROR);
            if (CarbonImmutable::parse($claim->expires_at)->lessThanOrEqualTo(now())) {
                $result['reason'] = 'PROBE_EXPIRED';
            } elseif (! $this->assetValid($pixel, (int) $claim->pixel_version)) {
                $result['reason'] = 'PROBE_TEST_ASSET_REQUIRED';
            } elseif (! $this->consented($privacy) || $privacy->updated_at !== ($context['privacy_updated_at'] ?? null)) {
                $result['reason'] = 'CONSENT_REQUIRED_OR_REVOKED';
            } elseif (($context['page_url'] ?? null) === $this->origin().self::PATH
                && filter_var($context['ip'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                && is_string($context['user_agent'] ?? null) && strlen($context['user_agent']) > 0 && strlen($context['user_agent']) <= 512
                && ! preg_match('/[\x00-\x1f\x7f]/', $context['user_agent'])) {
                $credential = app(Credentials::class)->forDelivery($pixel);
                if (! $credential || empty($credential['test_event_code'])) {
                    $result['reason'] = 'PROBE_TEST_CREDENTIALS_REQUIRED';
                } elseif (config('acquisition.transport') !== 'http') {
                    $result['reason'] = 'EXTERNAL_DISABLED';
                } else {
                    // Last privacy/runtime read immediately before transport; in-flight requests cannot be recalled.
                    $this->origin();
                    $latest = DB::table('acquisition_privacy')->where('id', $claim->privacy_id)->first();
                    if (! $this->assetValid(DB::table('acquisition_pixels')->where('id', $claim->pixel_id)->first(), (int) $claim->pixel_version)) {
                        $result['reason'] = 'PROBE_TEST_ASSET_REQUIRED';
                    } elseif (! $this->consented($latest) || $latest->updated_at !== $privacy->updated_at) {
                        $result['reason'] = 'CONSENT_REQUIRED_OR_REVOKED';
                    } else {
                        $payload = app(MetaPageTest::class)->payload($id, $claim->event_at, $context, $credential['test_event_code']);
                        $response = app(MetaAdapter::class)->send($pixel, $payload, $credential['token']);
                        $result = ['state' => $response->state === 'api_accepted' ? 'api_accepted' : ($response->state === 'blocked' ? 'blocked' : 'unconfirmed'),
                            'reason' => $response->error];
                    }
                }
            }
        } catch (Throwable) {
            $result = ['state' => 'unconfirmed', 'reason' => 'PROBE_RESULT_UNKNOWN_NO_RETRY'];
        }
        // No payload, token, IP, UA or identity survives completion. A crash stays attempted and is never reclaimed.
        DB::table('acquisition_page_tests')->where('id', $id)->where('state', 'attempted')->update($result + [
            'context_encrypted' => null, 'privacy_id' => null, 'pixel_id' => null, 'pixel_version' => null]);

        return $result;
    }

    public function withdraw(): void
    {
        $this->owner();
        abort_unless(request()->header('Origin') === $this->origin(), 403);
        app(TrackingContext::class)->consent(false, false);
    }
}
