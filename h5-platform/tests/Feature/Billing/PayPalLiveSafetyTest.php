<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Gateway\PayPal\LiveApi;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Services\BillingEngine;
use App\Modules\Billing\Services\PaymentLedger;
use App\Modules\Billing\Support\CheckoutSafety;
use App\Modules\Billing\Support\ProviderActivity;
use App\Support\DependencyNotReady;
use App\Support\RuntimeEnvironmentGuard;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Billing\Support\ProviderLedgerFixture;
use Tests\TestCase;

/** Loaded production configuration and database facts only. Never a real transport. */
class PayPalLiveSafetyTest extends TestCase
{
    use DatabaseMigrations;

    private array $environmentBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->environmentBefore = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];
        $this->app->register(BillingServiceProvider::class);
    }

    protected function tearDown(): void
    {
        $this->environment('testing');
        Request::setTrustedHosts([]);
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        [$server, $env, $process] = $this->environmentBefore;
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
        // Forward migration intentionally refuses to discard Live facts on rollback.
        DB::table('billing_paypal_merchant_bindings')->where('environment', 'live')->delete();
        foreach (['billing_refunds', 'billing_inbox', 'billing_payments', 'billing_orders', 'billing_subscriptions', 'billing_provider_resources', 'billing_provider_intents'] as $table) {
            DB::table($table)->where('provider', 'paypal')->delete();
        }
        parent::tearDown();
    }

    private function environment(string $environment): void
    {
        $this->app->instance('env', $environment);
        config(['app.env' => $environment]);
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $environment;
        putenv('APP_ENV='.$environment);
    }

    private function production(): void
    {
        $this->environment('production');
        config([
            'app.debug' => false, 'app.url' => 'https://billing.example.test',
            'h5.controlled_staging' => false, 'h5.simulation' => false, 'h5.local_us_test_region' => false,
            'h5.measurement_c_experience' => false, 'h5.measurement_c_registration' => false,
            'h5.acquisition_staging_test' => false, 'h5.local_modules' => '',
            'h5.modules.acquisition' => ['provider' => null, 'ready' => false],
            'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax',
            'trustedproxy.proxies' => null, 'billing.driver' => 'unconfigured',
            'billing.new_purchase_provider' => 'unconfigured', 'billing.paypal.enabled' => false,
            'mail.default' => 'smtp', 'mail.driver' => null, 'mail.from.address' => 'noreply@example.test',
            'mail.mailers' => ['smtp' => ['transport' => 'smtp', 'scheme' => 'smtp', 'url' => null,
                'host' => 'smtp.example.test', 'port' => '587', 'timeout' => 10,
                'username' => 'SYNTHETIC_NOT_SENT', 'password' => 'SYNTHETIC_NOT_SENT', 'require_tls' => true, 'verify_peer' => true]],
        ]);
    }

    public function test_live_defaults_off_and_does_not_inherit_any_sandbox_credentials(): void
    {
        self::assertFalse(config('billing.paypal_live.enabled'));
        config(['billing.paypal.client_id' => 'SYNTHETIC_SANDBOX', 'billing.paypal.client_secret' => 'SYNTHETIC_NOT_SENT']);
        self::assertNull(config('billing.paypal_live.client_id'));
        self::assertFalse((new LiveApi)->offlineFake());
        self::assertTrue(ProviderActivity::disabled('paypal', 'live'));
        $this->expectException(DependencyNotReady::class);
        (new LiveApi)->product('PROD-ABCDEFGHIJKLMNOPQ');
    }

    public function test_real_live_enablement_is_rejected_in_testing_local_and_staging(): void
    {
        foreach (['testing', 'local', 'staging'] as $environment) {
            $this->environment($environment);
            config(['billing.paypal_live.enabled' => true]);
            try {
                app(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
                self::fail('Live enablement must require production');
            } catch (LogicException $e) {
                self::assertSame('PAYPAL_LIVE_PRODUCTION_REQUIRED', $e->getMessage());
            }
        }
    }

    public function test_production_live_guard_requires_closed_local_and_ad_flags_and_exact_callback(): void
    {
        $this->production();
        config(['billing.paypal_live.enabled' => true, 'billing.new_purchase_provider' => 'paypal_live',
            'billing.paypal_live.webhook_url' => 'https://billing.example.test/webhooks/paypal/live']);
        $guard = app(RuntimeEnvironmentGuard::class);
        self::assertSame('https://billing.example.test', $guard->paypalLiveOrigin());
        $cases = ['app.debug' => true, 'h5.controlled_staging' => true, 'h5.simulation' => true,
            'h5.local_us_test_region' => true, 'h5.local_modules' => 'billing', 'h5.acquisition_staging_test' => true,
            'h5.measurement_c_experience' => true, 'h5.measurement_c_registration' => true,
            'billing.paypal_live.api_base' => 'https://untrusted.example.test',
            'billing.paypal_live.webhook_url' => 'https://billing.example.test/webhooks/paypal'];
        foreach ($cases as $key => $value) {
            $before = config()->all();
            config([$key => $value]);
            try {
                $guard->assertSafeRuntime();
                self::fail('Unsafe Live configuration accepted: '.$key);
            } catch (LogicException $e) {
                self::assertMatchesRegularExpression('/\A[A-Z0-9_]+\z/', $e->getMessage());
            } finally {
                config($before);
            }
        }
        self::assertSame('https://billing.example.test', $guard->paypalLiveOrigin());
    }

    public function test_http_fake_cannot_be_used_in_production_and_sandbox_bindings_are_not_live(): void
    {
        $fake = new LiveApi(new MockHandler);
        $this->production();
        config(['billing.paypal_live.enabled' => true]);
        self::assertFalse($fake->offlineFake());
        try {
            $fake->product('PROD-ABCDEFGHIJKLMNOPQ');
            self::fail('Production cannot use the offline transport');
        } catch (DependencyNotReady) {
        }
        foreach (['merchant_scope', 'client_id', 'webhook_id', 'product_id'] as $key) {
            config(['billing.paypal.'.$key => 'same', 'billing.paypal_live.'.$key => 'same']);
            try {
                app(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
                self::fail('Reused Sandbox binding');
            } catch (LogicException $e) {
                self::assertSame('PAYPAL_ENVIRONMENT_BINDING_REUSED', $e->getMessage());
            }
            config(['billing.paypal.'.$key => null, 'billing.paypal_live.'.$key => null]);
        }
    }

    public function test_production_access_never_comes_from_sandbox_and_survives_api_pause(): void
    {
        [$viewer, $order, $sub, $fact] = ProviderLedgerFixture::create(new ProviderScope('paypal', 'sandbox', 'sandbox-only'));
        app(PaymentLedger::class)->record($sub->id, $fact);
        self::assertTrue(app(BillingEngine::class)->membership($viewer->id, now()->toDateTimeImmutable())->access_active);
        $this->production();
        $member = app(BillingEngine::class)->membership($viewer->id, now()->toDateTimeImmutable());
        self::assertFalse($member->access_active);
        self::assertFalse($member->renewal_enabled);
        foreach (['billing_orders', 'billing_subscriptions', 'billing_payments'] as $table) {
            DB::table($table)->update(['provider_environment' => 'live', 'is_test' => false]);
        }
        self::assertTrue(app(BillingEngine::class)->membership($viewer->id, now()->toDateTimeImmutable())->access_active);
        self::assertTrue(ProviderActivity::disabled('paypal', 'live'));
    }

    public function test_live_pause_filters_before_limit_without_pausing_sandbox(): void
    {
        [$a, $orderA] = ProviderLedgerFixture::create(new ProviderScope('paypal', 'sandbox', 'sandbox-only'));
        [$b, $orderB] = ProviderLedgerFixture::create(new ProviderScope('paypal', 'sandbox', 'second-fixture'));
        DB::table('billing_orders')->where('id', $orderB->id)->update(['provider_environment' => 'live', 'is_test' => false]);
        config(['billing.paypal.enabled' => true]);
        self::assertSame([$orderA->id], ProviderActivity::eligible(DB::table('billing_orders'))->limit(1)->pluck('id')->all());
        $this->app->instance(LiveApi::class, new LiveApi(new MockHandler));
        config(['billing.paypal.enabled' => false]);
        self::assertSame([$orderB->id], ProviderActivity::eligible(DB::table('billing_orders'))->limit(1)->pluck('id')->all());
    }

    public function test_only_the_exact_live_callback_is_csrf_exempt(): void
    {
        $this->production(); // Laravel's test CSRF shortcut is now disabled.
        $this->withServerVariables(['HTTP_HOST' => 'billing.example.test', 'HTTPS' => 'on', 'SERVER_PORT' => 443]);
        $this->postJson('https://billing.example.test/api/v1/billing/checkout', [])->assertStatus(419);
        $this->postJson('https://billing.example.test/api/v1/billing/subscription/cancel', [])->assertStatus(419);
        $this->postJson('https://billing.example.test/webhooks/paypal/live', [])->assertStatus(503);
        $this->postJson('https://billing.example.test/webhooks/paypal/live/anything', [])->assertNotFound();
    }

    public function test_live_price_requires_explicit_production_confirmation_and_appends_without_network(): void
    {
        $options = ['--expected-version' => '0', '--regular-usd' => '13.25', '--first-week-usd' => '4.50', '--confirm-live-price' => true];
        $this->artisan('billing:paypal-live-price', $options)->assertExitCode(1);
        self::assertDatabaseCount('billing_prices', 0);
        $this->production();
        $without = $options;
        unset($without['--confirm-live-price']);
        $this->artisan('billing:paypal-live-price', $without)->assertExitCode(1);
        $this->artisan('billing:paypal-live-price', $options)->assertExitCode(0);
        $price = DB::table('billing_prices')->first();
        self::assertSame(1325, $price->regular_amount_minor);
        self::assertSame(450, $price->first_week_amount_minor);
        self::assertFalse($price->is_test);
        $this->artisan('billing:paypal-live-price', $options)->assertExitCode(1); // stale version
        self::assertDatabaseCount('billing_prices', 1);
        self::assertDatabaseHas('identity_audit_events', ['action' => 'billing.price.created', 'actor_kind' => 'system']);
        self::assertFalse(config('billing.paypal_live.enabled'));
        config(['billing.paypal_live.enabled' => true]);
        $url = app(CheckoutSafety::class)->expectedReturnUrl((object) ['id' => 'fixed-order', 'provider' => 'paypal', 'provider_environment' => 'live', 'return_to' => '/membership?order_id=untrusted']);
        self::assertSame('https://billing.example.test/membership?order_id=fixed-order', $url);
    }
}
