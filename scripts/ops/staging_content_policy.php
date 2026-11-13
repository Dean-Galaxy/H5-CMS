<?php

namespace H5Ops;

// Admission for content tasks only; never enables payment or performs provider I/O.
final class StagingContentPolicy
{
    public static function check(array $c, callable $sandboxBinding): string
    {
        $lane = self::lane($c);
        if ($lane === 'paypal_sandbox') {
            $sandboxBinding();
        }

        return $lane;
    }

    public static function assertLocalSandboxBinding(): void
    {
        $scope = app(\App\Modules\Billing\Services\PayPalRuntime::class)->scope('sandbox');
        app(\App\Modules\Billing\Services\PayPalMerchantBinding::class)->assertBound($scope);
    }

    private static function lane(array $c): string
    {
        if (($c['environment'] ?? null) !== 'staging' || ($c['workspace'] ?? null) !== 'h5_page_test'
            || ($c['controlled'] ?? null) !== true || ($c['acquisition_test'] ?? null) !== true
            || ($c['cached'] ?? null) !== true || ($c['mail'] ?? null) !== 'array'
            || ($c['driver'] ?? null) !== 'unconfigured' || ($c['live']['enabled'] ?? null) !== false
            || ($c['ads']['transport'] ?? null) !== 'disabled') {
            throw new \RuntimeException;
        }
        foreach (['external_enabled', 'platform_reviewed', 'analytics_enabled', 'measurement.enabled',
            'measurement.synthetic_policy', 'registration.observations_enabled', 'registration.synthetic_policy'] as $key) {
            if (($c['ads'][$key] ?? null) !== false) {
                throw new \RuntimeException;
            }
        }
        $provider = $c['provider'] ?? null;
        $sandbox = $c['sandbox'] ?? [];
        if ($provider === 'unconfigured' && ($sandbox['enabled'] ?? null) === false) {
            return 'disabled';
        }
        if ($provider !== 'paypal' || ($sandbox['enabled'] ?? null) !== true
            || ! in_array($c['stripe_secret'] ?? null, [null, ''], true)
            || ! in_array($c['stripe_webhook_secret'] ?? null, [null, ''], true)
            || rtrim($c['origin'] ?? '', '/') !== 'https://staging.sparklingshort.com') {
            throw new \RuntimeException;
        }
        foreach (['client_id', 'client_secret', 'webhook_id', 'merchant_id', 'merchant_scope', 'product_id', 'webhook_url'] as $key) {
            if (! is_string($sandbox[$key] ?? null) || preg_match('/\A[\x21-\x7e]{1,4096}\z/D', $sandbox[$key]) !== 1
                || ! in_array($c['live'][$key] ?? null, [null, ''], true)) {
                throw new \RuntimeException;
            }
        }
        if ($sandbox['webhook_id'] === 'WEBHOOK_ID'
            || $sandbox['webhook_url'] !== 'https://staging.sparklingshort.com/webhooks/paypal'
            || preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $sandbox['merchant_scope']) !== 1
            || preg_match('/\A[A-Z0-9]{1,64}\z/D', $sandbox['merchant_id']) !== 1
            || preg_match('/\APROD-[A-Z0-9]{17}\z/D', $sandbox['product_id']) !== 1) {
            throw new \RuntimeException;
        }

        return 'paypal_sandbox';
    }
}
