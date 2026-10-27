<?php

namespace App\Support;

use Illuminate\Support\Env;
use LogicException;

/** Checks loaded configuration only. Never connects to mail, payment providers or a region service. */
final class RuntimeEnvironmentGuard
{
    public function assertSafeRuntime(): void
    {
        $environment = config('app.env');
        $this->require(in_array($environment, ['local', 'testing', 'staging', 'production'], true)
            && $environment === app()->environment() && $environment === Env::get('APP_ENV'), 'RUNTIME_ENVIRONMENT_INVALID');
        foreach (['app.debug', 'h5.controlled_staging', 'h5.simulation', 'h5.local_us_test_region'] as $key) {
            $this->require(is_bool(config($key)), 'RUNTIME_BOOLEAN_INVALID');
        }
        $this->require(is_string(config('h5.local_modules')), 'RUNTIME_MODULES_INVALID');
        $this->require(! config('h5.controlled_staging') || $environment === 'staging', 'STAGING_ENVIRONMENT_REQUIRED');
        $this->paypal($environment);
        if (in_array($environment, ['local', 'testing'], true)) {
            return;
        }

        $this->require(config('app.debug') === false, 'RUNTIME_DEBUG_FORBIDDEN');
        $this->require(config('h5.simulation') === false && config('h5.local_us_test_region') === false
            && config('h5.local_modules') === '', 'RUNTIME_LOCAL_OVERRIDE_FORBIDDEN');
        $this->origin();
        $this->require(config('session.secure') === true && config('session.http_only') === true
            && config('session.same_site') === 'lax', 'RUNTIME_COOKIE_UNSAFE');
        $this->require(in_array(config('trustedproxy.proxies'), [null, []], true), 'RUNTIME_PROXY_FORBIDDEN');
        $this->require(config('h5.modules.acquisition.provider') === null
            && config('h5.modules.acquisition.ready') === false, 'RUNTIME_ACQUISITION_NOT_RELEASED');
        $this->require(config('mail.driver') === null, 'RUNTIME_MAIL_LEGACY_FORBIDDEN');
        $visited = 0;
        $this->mailer(config('mail.default'), [], $visited);
        $from = config('mail.from.address');
        $this->require(is_string($from) && filter_var($from, FILTER_VALIDATE_EMAIL) !== false, 'RUNTIME_MAIL_FROM_INVALID');
    }

    /** Validate shape at boot; missing credentials remain unavailable to the adapter. */
    private function paypal(string $environment): void
    {
        $provider = config('billing.new_purchase_provider', 'unconfigured');
        $this->require(in_array($provider, ['unconfigured', 'stripe', 'paypal'], true), 'BILLING_PURCHASE_PROVIDER_INVALID');
        $paypal = config('billing.paypal', ['enabled' => false]);
        $this->require(is_array($paypal) && is_bool($paypal['enabled'] ?? null), 'PAYPAL_ENABLED_INVALID');
        $keys = ['enabled', 'client_id', 'client_secret', 'webhook_id', 'merchant_id', 'merchant_scope', 'product_id'];
        $this->require(array_diff(array_keys($paypal), $keys) === [], 'PAYPAL_CONFIGURATION_INVALID');
        foreach (array_slice($keys, 1) as $key) {
            $value = $paypal[$key] ?? null;
            $this->require($value === null || $value === '' || (is_string($value)
                && preg_match('/\A[\x21-\x7e]{1,4096}\z/D', $value) === 1), 'PAYPAL_CONFIGURATION_INVALID');
        }
        $scope = $paypal['merchant_scope'] ?? null;
        $this->require($scope === null || $scope === '' || preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $scope) === 1,
            'PAYPAL_SCOPE_INVALID');
        $this->require(($paypal['webhook_id'] ?? null) !== 'WEBHOOK_ID', 'PAYPAL_SIMULATOR_WEBHOOK_FORBIDDEN');
        $this->require($provider !== 'paypal' || $paypal['enabled'] === true, 'PAYPAL_PURCHASE_NOT_ENABLED');
        if ($paypal['enabled']) {
            $this->require(in_array($environment, ['local', 'testing', 'staging'], true), 'PAYPAL_PRODUCTION_FORBIDDEN');
            $this->require($environment !== 'staging' || config('h5.controlled_staging') === true, 'PAYPAL_STAGING_NOT_ENABLED');
        }
    }

    public function controlledStagingOrigin(): string
    {
        // Deliberately recheck: a singleton instance must not cache an earlier successful decision.
        $this->assertSafeRuntime();
        $this->require(app()->environment() === 'staging' && config('h5.controlled_staging') === true, 'STAGING_NOT_ENABLED');

        return $this->origin();
    }

    private function origin(): string
    {
        $url = config('app.url');
        $this->require(is_string($url) && strlen($url) <= 2048
            && preg_match('~\Ahttps://([a-zA-Z0-9.-]+)(?::443)?/?\z~D', $url, $match) === 1
            && $this->hostname($match[1]), 'RUNTIME_ORIGIN_INVALID');

        return 'https://'.strtolower($match[1]);
    }

    private function hostname(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 253 && str_contains($value, '.')
            && ! str_ends_with($value, '.') && ! str_ends_with(strtolower($value), '.localhost')
            // A public DNS suffix cannot be a shortened/octal/hexadecimal IPv4 component.
            && preg_match('/\.[a-zA-Z][a-zA-Z0-9-]*\z/D', $value) === 1
            && filter_var($value, FILTER_VALIDATE_IP) === false
            && filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function mailer(mixed $name, array $ancestors, int &$visited): void
    {
        $this->require(++$visited <= 64, 'RUNTIME_MAIL_PATH_INVALID');
        $this->require(is_string($name) && preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $name) === 1
            && count($ancestors) < 8 && ! in_array($name, $ancestors, true), 'RUNTIME_MAIL_PATH_INVALID');
        $mail = config('mail.mailers.'.$name);
        $this->require(is_array($mail) && is_string($mail['transport'] ?? null), 'RUNTIME_MAIL_PATH_INVALID');
        if (in_array($mail['transport'], ['failover', 'roundrobin'], true)) {
            $this->require(array_diff(array_keys($mail), ['transport', 'mailers', 'retry_after']) === []
                && is_array($mail['mailers'] ?? null) && array_is_list($mail['mailers'])
                && count($mail['mailers']) >= 1 && count($mail['mailers']) <= 8
                && $this->integer($mail['retry_after'] ?? 60, 1, 3600), 'RUNTIME_MAIL_PATH_INVALID');
            foreach ($mail['mailers'] as $child) {
                $this->mailer($child, [...$ancestors, $name], $visited);
            }

            return;
        }

        // First approved leaf: the installed SMTP transport with mandatory TLS.
        // Other transports need a reviewed adapter/configuration before joining this allowlist.
        $allowed = ['transport', 'scheme', 'url', 'host', 'port', 'username', 'password', 'timeout',
            'local_domain', 'require_tls', 'auto_tls', 'verify_peer'];
        $this->require($mail['transport'] === 'smtp' && array_diff(array_keys($mail), $allowed) === [], 'RUNTIME_MAIL_TRANSPORT_FORBIDDEN');
        $this->require(($mail['url'] ?? null) === null
            && in_array($mail['scheme'] ?? null, ['smtp', 'smtps'], true)
            && ($mail['require_tls'] ?? null) === true
            && ($mail['auto_tls'] ?? true) === true && ($mail['verify_peer'] ?? true) === true,
            'RUNTIME_MAIL_TLS_REQUIRED');
        $this->require($this->hostname($mail['host'] ?? null) && $this->integer($mail['port'] ?? null, 1, 65535)
            && $this->integer($mail['timeout'] ?? null, 1, 120), 'RUNTIME_MAIL_ENDPOINT_INVALID');
        foreach (['username', 'password'] as $field) {
            $value = $mail[$field] ?? null;
            $this->require(is_string($value) && $value !== '' && strlen($value) <= 4096
                && ! preg_match('/[\x00-\x1f\x7f]/', $value), 'RUNTIME_MAIL_CREDENTIALS_REQUIRED');
        }
        $this->require(($mail['local_domain'] ?? null) === null || $this->hostname($mail['local_domain']), 'RUNTIME_MAIL_ENDPOINT_INVALID');
    }

    private function integer(mixed $value, int $min, int $max): bool
    {
        return (is_int($value) || (is_string($value) && preg_match('/\A[1-9][0-9]{0,5}\z/D', $value)))
            && $value >= $min && $value <= $max;
    }

    private function require(bool $condition, string $reason): void
    {
        if (! $condition) {
            // Fixed diagnostic only: never interpolate configuration values or secrets.
            throw new LogicException($reason);
        }
    }
}
