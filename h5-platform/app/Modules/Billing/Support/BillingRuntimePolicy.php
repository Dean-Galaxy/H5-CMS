<?php

namespace App\Modules\Billing\Support;

use App\Support\DependencyNotReady;
use App\Support\RuntimeEnvironmentGuard;
use LogicException;
use Throwable;

/** Loaded-configuration policy only: no network, database or cached readiness. */
final class BillingRuntimePolicy
{
    public const API_VERSION = '2026-08-26.dahlia';

    public const API_BASE = 'https://api.stripe.com';

    public function assertSafeConfiguration(): void
    {
        app(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
        $driver = config('billing.driver');
        $this->require(in_array($driver, ['unconfigured', 'stripe_test'], true), 'BILLING_DRIVER_INVALID');
        $this->optionalToken(config('billing.stripe_secret'), '/\Ask_test_[A-Za-z0-9_]+\z/D', 'BILLING_STRIPE_SECRET_INVALID');
        $this->optionalToken(config('billing.stripe_webhook_secret'), '/\Awhsec_[A-Za-z0-9_]+\z/D', 'BILLING_WEBHOOK_SECRET_INVALID');
        $this->require(config('billing.api_version') === self::API_VERSION, 'BILLING_API_VERSION_INVALID');
        $this->require(config('billing.api_base', self::API_BASE) === self::API_BASE, 'BILLING_API_BASE_INVALID');
        $clock = config('billing.test_clock_id');
        $this->require($clock === null || (is_string($clock) && preg_match('/\Aclock_[A-Za-z0-9]+\z/D', $clock) === 1), 'BILLING_TEST_CLOCK_INVALID');

        $environment = app()->environment();
        if ($driver === 'stripe_test') {
            $this->require(in_array($environment, ['local', 'testing', 'staging'], true), 'BILLING_ENVIRONMENT_FORBIDDEN');
            if ($environment === 'staging') {
                $this->require(config('h5.controlled_staging') === true, 'BILLING_STAGING_NOT_ENABLED');
                app(RuntimeEnvironmentGuard::class)->controlledStagingOrigin();
            }
        }
        if (in_array($environment, ['local', 'testing'], true)) {
            $this->localOrigin();
        }
    }

    public function assertAvailable(): void
    {
        try {
            $this->assertSafeConfiguration();
            $this->require(config('billing.driver') === 'stripe_test'
                && ! $this->missing(config('billing.stripe_secret'))
                && ! $this->missing(config('billing.stripe_webhook_secret')), 'BILLING_CREDENTIALS_REQUIRED');
        } catch (Throwable) {
            // Neither public guard diagnostics nor configuration values reach a payment response.
            throw new DependencyNotReady('Billing / Stripe 受控测试配置');
        }
    }

    /** A trusted return origin is useful even while payment credentials are absent. */
    public function checkoutOrigin(): string
    {
        try {
            $this->assertSafeConfiguration();
            if (app()->environment() === 'staging') {
                return app(RuntimeEnvironmentGuard::class)->controlledStagingOrigin();
            }
            $this->require(app()->environment('local', 'testing'), 'BILLING_ENVIRONMENT_FORBIDDEN');

            return $this->localOrigin();
        } catch (Throwable) {
            throw new DependencyNotReady('Billing / Stripe 受控测试配置');
        }
    }

    private function localOrigin(): string
    {
        $url = config('app.url');
        $this->require(is_string($url) && strlen($url) <= 2048
            && preg_match('~\A(https?)://(\[[0-9a-fA-F:]+\]|[a-zA-Z0-9.-]+)(?::([1-9][0-9]{0,4}))?/?\z~D', $url, $parts) === 1,
            'BILLING_ORIGIN_INVALID');
        $scheme = $parts[1];
        $host = strtolower($parts[2]);
        $port = isset($parts[3]) ? (int) $parts[3] : null;
        $loopback = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        $hostname = strlen($host) <= 253 && str_contains($host, '.') && ! str_ends_with($host, '.')
            && preg_match('/\.[a-zA-Z][a-zA-Z0-9-]*\z/D', $host) === 1
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
        $this->require(($loopback || ($scheme === 'https' && $hostname))
            && ($port === null || ($port >= 1 && $port <= 65535)), 'BILLING_ORIGIN_INVALID');
        $defaultPort = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.$host.($port !== null && $port !== $defaultPort ? ':'.$port : '');
    }

    private function optionalToken(mixed $value, string $pattern, string $reason): void
    {
        $this->require($this->missing($value) || (is_string($value) && strlen($value) <= 4096
            && preg_match($pattern, $value) === 1), $reason);
    }

    private function missing(mixed $value): bool
    {
        // Whitespace, booleans and numbers are invalid configuration, not missing credentials.
        return $value === null || $value === '';
    }

    private function require(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new LogicException($reason);
        }
    }
}
