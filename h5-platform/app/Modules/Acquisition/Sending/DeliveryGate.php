<?php

namespace App\Modules\Acquisition\Sending;

use App\Modules\Acquisition\Registration\LegacyAdvertisingBridge;
use App\Modules\Acquisition\Services\CheckoutContextStore;
use App\Support\RuntimeEnvironmentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DeliveryGate
{
    public static function runtimeReason(): ?string
    {
        if (! app()->environment('local', 'testing') || config('h5.acquisition_staging_test') !== false) {
            try {
                app(RuntimeEnvironmentGuard::class)->controlledAcquisitionTestOrigin();
            } catch (\Throwable) {
                return 'STAGING_TEST_RUNTIME_INVALID';
            }
        }

        return null;
    }

    public function reason(object $delivery, object $receipt, ?object $source, ?object $pixel, array $context): ?string
    {
        if ($reason = self::runtimeReason()) {
            return $reason;
        }
        if (! $source || $source->consent !== 'granted' || $source->revoked_at !== null || $source->policy_version === '') {
            return 'CONSENT_REQUIRED_OR_REVOKED';
        }
        if ($source->privacy_id ?? null) {
            $privacy = DB::table('acquisition_privacy')->where('id', $source->privacy_id)->first();
            if (! $privacy || $privacy->advertising !== 'granted' || app(LegacyAdvertisingBridge::class)->markerDenied($privacy)) {
                return 'CONSENT_REQUIRED_OR_REVOKED';
            }
        }
        if (! $pixel || ! $pixel->active) {
            return 'PIXEL_DISABLED';
        }
        if (($receipt->is_test && $delivery->environment !== 'test') || (! $receipt->is_test && $delivery->environment !== 'production')) {
            return 'PAYMENT_ASSET_ENVIRONMENT_MISMATCH';
        }
        if (config('h5.acquisition_staging_test') === true) {
            if (! $receipt->is_test || $delivery->environment !== 'test') {
                return 'STAGING_TEST_ASSET_REQUIRED';
            }
            if ($receipt->transaction_kind === 'renewal') {
                return 'STAGING_RENEWAL_NOT_RELEASED';
            }
        }
        $mode = config('acquisition.transport');
        if ($mode === 'disabled') {
            return 'EXTERNAL_DISABLED';
        }
        if ($mode === 'local_simulation') {
            return app()->environment('local', 'testing') && $source->is_simulation && $receipt->is_test && $delivery->environment === 'test'
                ? null : 'SIMULATION_ENVIRONMENT_REQUIRED';
        }
        if ($mode !== 'http' || ! config('acquisition.external_enabled') || ! config('acquisition.platform_reviewed')) {
            return 'EXTERNAL_REVIEW_REQUIRED';
        }
        if ($source->is_simulation) {
            return 'SIMULATED_SOURCE_EXTERNAL_FORBIDDEN';
        }
        if (app()->environment('local', 'testing') && $delivery->environment !== 'test') {
            return 'LOCAL_PRODUCTION_ASSET_FORBIDDEN';
        }
        if (app()->environment('production') && $delivery->environment !== 'production') {
            return 'PRODUCTION_TEST_ASSET_FORBIDDEN';
        }
        // A conservative 24-hour retry budget stays inside TikTok's documented
        // 48-hour event/event_id deduplication window. This is not D05 attribution.
        if (($delivery->attempts > 0 && ! $delivery->first_attempted_at)
            || ($delivery->first_attempted_at && CarbonImmutable::parse($delivery->first_attempted_at)->addDay()->lessThanOrEqualTo(now()))) {
            return 'RETRY_WINDOW_EXPIRED';
        }
        if (($context['_checkout_status'] ?? null) !== 'valid') {
            return $context['_checkout_status'] ?? 'CHECKOUT_CONTEXT_REQUIRED';
        }
        if (! CheckoutContextStore::validContext($context)) {
            return 'MATCH_CONTEXT_REQUIRED';
        }
        // The first-party attribution expiry is not the platform's accepted event-age window.
        // A conservative delivery cutoff prevents ancient recovery jobs becoming new conversions.
        $time = CarbonImmutable::parse($receipt->occurred_at);
        if ($time->greaterThan(now()->addMinute()) || $time->lessThan(now()->subDays(7))) {
            return 'PLATFORM_EVENT_TIME_OUT_OF_RANGE';
        }

        return null;
    }
}
