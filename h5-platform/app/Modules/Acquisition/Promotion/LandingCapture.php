<?php

namespace App\Modules\Acquisition\Promotion;

use App\Contracts\PromotionLandingEvidence;
use App\Modules\Acquisition\Analytics\Clock;
use App\Modules\Acquisition\Analytics\TrackingContext;
use App\Modules\Acquisition\Registration\LegacyAdvertisingBridge;
use App\Modules\Acquisition\Services\AttributionRecorder;
use App\Support\RuntimeEnvironmentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Internal implementation; the public successful-render facade is supplied/bound by 00. */
final class LandingCapture implements PromotionLandingEvidence
{
    public function record(string $dramaId): void
    {
        try {
            DB::transaction(function () use ($dramaId) {
                $origin = app(RuntimeEnvironmentGuard::class)->controlledAcquisitionTestOrigin();
                $r = request();
                $c = app(TrackingContext::class);
                $pending = $c->session()?->get('tracking.pending');
                if (! Str::isUuid($dramaId) || ! $r->isMethod('GET') || $r->getPathInfo() !== '/dramas/'.$dramaId
                    || ! $r->isSecure() || $r->getSchemeAndHttpHost() !== $origin || ! app(AttributionRecorder::class)->eligibleRequest()
                    || ! $pending || ! Str::isUuid($pending['key'] ?? '') || CarbonImmutable::parse($pending['created_at'])->addDay()->lessThanOrEqualTo(now())) {
                    return;
                }
                foreach (['Forwarded', 'X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-Port'] as $h) {
                    if ($r->headers->has($h)) {
                        return;
                    }
                }
                if (! in_array($r->header('Sec-Fetch-Site'), [null, 'none', 'same-origin'], true)) {
                    return;
                }
                $link = DB::table('acquisition_links')->where('id', $pending['link_id'])->first();
                $pixel = $link ? DB::table('acquisition_pixels')->where('id', $link->pixel_id)->first() : null;
                if (! $link || ! $link->active || $link->drama_id !== $dramaId || $link->platform !== 'meta'
                    || ! $pixel || ! $pixel->active || $pixel->platform !== 'meta' || $pixel->environment !== 'test') {
                    return;
                }
                // Necessary one-page functional proof only; no consent grant, IP, UA or advertising event at GET.
                $c->session()->put('tracking.promotion_get', ['key' => $pending['key'], 'link_id' => $link->id, 'drama_id' => $dramaId, 'at' => Clock::stamp()]);
            });
        } catch (Throwable) {
            app(TrackingContext::class)->session()?->forget('tracking.promotion_get');
        }
    }

    public function evidence(array $pending, string $dramaId, object $privacy, object $pixel): ?array
    {
        try {
            $origin = app(RuntimeEnvironmentGuard::class)->controlledAcquisitionTestOrigin();
            $r = request();
            $data = app(TrackingContext::class)->session()?->get('tracking.promotion_get');
            if (! is_array($data) || ($data['key'] ?? null) !== $pending['key'] || $data['link_id'] !== $pending['link_id'] || $data['drama_id'] !== $dramaId
                || ! $r->isMethod('POST') || $r->getPathInfo() !== '/api/v1/tracking/landing' || ! $r->isSecure()
                || $r->getSchemeAndHttpHost() !== $origin || $r->header('Origin') !== $origin
                || ! in_array($r->header('Sec-Fetch-Site'), [null, 'same-origin'], true)
                || ! app(AttributionRecorder::class)->eligibleRequest() || $privacy->advertising !== 'granted'
                || app(LegacyAdvertisingBridge::class)->denied($privacy) || $pixel->platform !== 'meta' || $pixel->environment !== 'test') {
                return null;
            }
            foreach (['Forwarded', 'X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-Port'] as $h) {
                if ($r->headers->has($h)) {
                    return null;
                }
            }
            $at = CarbonImmutable::parse($data['at']);
            if ($at->greaterThan(now()) || $at->addMinutes(10)->lessThanOrEqualTo(now())
                || ! filter_var($r->server('REMOTE_ADDR'), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || ! is_string($r->userAgent()) || strlen($r->userAgent()) < 1 || strlen($r->userAgent()) > 512
                || preg_match('/[\x00-\x1f\x7f]/', $r->userAgent())) {
                return null;
            }

            // The event is the consented visible confirmation NOW, never a retrospective pre-consent timestamp.
            return ['privacy_updated_at' => $privacy->updated_at, 'at' => Clock::stamp(),
                'page_url' => $origin.'/dramas/'.$dramaId, 'ip' => $r->server('REMOTE_ADDR'), 'user_agent' => $r->userAgent()];
        } catch (Throwable) {
            return null;
        }
    }

    public function enqueue(object $source, object $link, object $pixel, array $evidence): void
    {
        // Called under the original landing transaction/advisory lock. Unique landing_key is a second guard.
        DB::table('acquisition_promotion_pageviews')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'landing_key' => $source->landing_key, 'source_id' => $source->id,
            'link_id' => $link->id, 'drama_id' => $link->drama_id, 'pixel_id' => $pixel->id, 'pixel_version' => $source->pixel_version,
            'privacy_id' => $source->privacy_id, 'privacy_updated_at' => $evidence['privacy_updated_at'],
            'context_encrypted' => Crypt::encryptString(json_encode(array_intersect_key($evidence, array_flip(['page_url', 'ip', 'user_agent'])), JSON_THROW_ON_ERROR)),
            'event_at' => $evidence['at'], 'expires_at' => Clock::stamp(CarbonImmutable::parse($evidence['at'])->addDay()->min(CarbonImmutable::parse($source->expires_at))),
            'state' => 'pending', 'created_at' => Clock::stamp(),
        ]);
        app(TrackingContext::class)->session()?->forget('tracking.promotion_get');
    }
}
