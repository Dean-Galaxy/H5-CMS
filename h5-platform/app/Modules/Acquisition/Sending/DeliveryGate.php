<?php

namespace App\Modules\Acquisition\Sending;

use Carbon\CarbonImmutable;

final class DeliveryGate
{
    public function reason(object $delivery, object $receipt, ?object $source, ?object $pixel, array $context): ?string
    {
        if (! $source || $source->consent !== 'granted' || $source->revoked_at !== null || $source->policy_version === '') {
            return 'CONSENT_REQUIRED_OR_REVOKED';
        }
        if (! $pixel || ! $pixel->active) {
            return 'PIXEL_DISABLED';
        }
        if (($receipt->is_test && $delivery->environment !== 'test') || (! $receipt->is_test && $delivery->environment !== 'production')) {
            return 'PAYMENT_ASSET_ENVIRONMENT_MISMATCH';
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
        if (! $this->validContext($context)) {
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

    private function validContext(array $c): bool
    {
        $url = $c['page_url'] ?? '';
        $parts = is_string($url) ? parse_url($url) : false;

        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === parse_url(config('app.url'), PHP_URL_HOST)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && is_string($c['user_agent'] ?? null) && strlen($c['user_agent']) > 0 && strlen($c['user_agent']) <= 512
            && filter_var($c['ip'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
