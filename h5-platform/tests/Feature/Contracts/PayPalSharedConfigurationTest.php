<?php

namespace Tests\Feature\Contracts;

use App\Support\RuntimeEnvironmentGuard;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

class PayPalSharedConfigurationTest extends TestCase
{
    public function test_default_is_off_and_missing_credentials_do_not_make_network_calls(): void
    {
        $this->assertFalse(config('billing.paypal.enabled'));
        $this->assertSame('unconfigured', config('billing.new_purchase_provider'));
        config(['billing.paypal.enabled' => true, 'billing.new_purchase_provider' => 'paypal']);
        app(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
        $this->assertNull(config('billing.paypal.client_secret'));
    }

    public function test_invalid_configuration_fails_with_fixed_nonsecret_diagnostics(): void
    {
        $safe = config('billing');
        $cases = [
            ['billing.paypal.enabled', 'false'],
            ['billing.paypal.enabled', 1],
            ['billing.paypal.enabled', null],
            ['billing.new_purchase_provider', 'paypal_live'],
            ['billing.new_purchase_provider', 'paypal'],
            ['billing.paypal.client_secret', "SYNTHETIC_SECRET\n"],
            ['billing.paypal.client_id', false],
            ['billing.paypal.merchant_scope', 'another merchant'],
            ['billing.paypal.webhook_id', 'WEBHOOK_ID'],
            ['billing.paypal.api_base', 'https://untrusted.example.test'],
        ];
        foreach ($cases as [$key, $value]) {
            config(['billing' => $safe, $key => $value]);
            try {
                app(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
                $this->fail('Invalid configuration accepted: '.$key);
            } catch (LogicException $e) {
                $this->assertMatchesRegularExpression('/\A[A-Z0-9_]+\z/', $e->getMessage());
                $this->assertStringNotContainsString('SYNTHETIC_SECRET', $e->getMessage());
            }
        }
    }

    public function test_webhook_registration_target_is_optional_but_strictly_callback_only(): void
    {
        $guard = app(RuntimeEnvironmentGuard::class);
        foreach ([null, '', 'https://callback.example.test/webhooks/paypal', 'https://callback.example.test:443/webhooks/paypal'] as $url) {
            config(['billing.paypal.webhook_url' => $url]);
            $guard->assertSafeRuntime();
            $this->assertSame($url, config('billing.paypal.webhook_url'));
        }
        foreach ([
            'http://callback.example.test/webhooks/paypal',
            'https://user:password@callback.example.test/webhooks/paypal',
            'https://callback.example.test:8443/webhooks/paypal',
            'https://callback.example.test/webhooks/paypal?x=1',
            'https://callback.example.test/webhooks/paypal#fragment',
            'https://callback.example.test/webhooks/paypal/',
            'https://callback.example.test/admin',
            'https://callback.example.test/%77ebhooks/paypal',
            'https://127.0.0.1/webhooks/paypal',
            'https://localhost/webhooks/paypal',
            'https://a.localhost/webhooks/paypal',
            'https://callback.example.test./webhooks/paypal',
            '//callback.example.test/webhooks/paypal',
            'https://callback.example.test/webhooks/paypal' . "\n",
            false,
        ] as $url) {
            config(['billing.paypal.webhook_url' => $url]);
            try {
                $guard->assertSafeRuntime();
                $this->fail('Unsafe webhook registration target accepted');
            } catch (LogicException $e) {
                $this->assertContains($e->getMessage(), ['PAYPAL_WEBHOOK_URL_INVALID', 'PAYPAL_CONFIGURATION_INVALID']);
            }
        }
    }

    public function test_paypal_is_rejected_in_production_and_uncontrolled_staging(): void
    {
        $before = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];
        try {
            foreach (['production', 'staging'] as $environment) {
                $this->app->instance('env', $environment);
                $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $environment;
                putenv('APP_ENV='.$environment);
                config(['app.env' => $environment, 'billing.paypal.enabled' => true]);
                try {
                    app(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
                    $this->fail('Unsafe environment accepted');
                } catch (LogicException $e) {
                    $this->assertSame($environment === 'production' ? 'PAYPAL_PRODUCTION_FORBIDDEN' : 'PAYPAL_STAGING_NOT_ENABLED', $e->getMessage());
                }
            }
        } finally {
            [$server, $env, $process] = $before;
            if ($server === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $server;
            }
            if ($env === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $env;
            }
            putenv($process === false ? 'APP_ENV' : 'APP_ENV='.$process);
        }
    }

    public function test_csrf_exception_is_limited_to_exact_webhook_path(): void
    {
        // Disable Laravel's unit-test CSRF bypass without changing HTTP route behavior.
        $this->app->instance('env', 'local');
        foreach (['webhooks/paypal', 'webhooks/paypal/extra', 'api/v1/billing/paypal-test'] as $path) {
            Route::middleware('web')->post($path, fn () => response('accepted'));
        }
        $this->post('/webhooks/paypal')->assertOk();
        $this->post('/webhooks/paypal/extra')->assertStatus(419);
        $this->post('/api/v1/billing/paypal-test')->assertStatus(419);
    }
}
