<?php

namespace Tests\Feature\Audience;

use App\Contracts\RegionResolver;
use App\Data\ResolvedRegion;
use App\Data\ViewerIdentity;
use App\Modules\Audience\AudienceServiceProvider;
use App\Modules\Audience\Billing\BillingController;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Gateway\PayPal\LiveApi;
use App\Modules\Billing\Gateway\PayPal\SandboxApi;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Identity\Models\Viewer;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Real Audience HTTP plus real Billing/LiveApi and an explicit offline HTTP Fake; no external calls. */
final class PayPalLiveCheckoutUiTest extends TestCase
{
    use DatabaseMigrations;

    private const PLAN = 'P-ABCDEFGHIJKLMNOPQRSTUVWX';

    private const PRODUCT = 'PROD-ABCDEFGHIJKLMNOPQ';

    private const SUBSCRIPTION = 'I-FIXTURE';

    private array $calls = [];

    private array $plan = [];

    private array $remote = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(BillingServiceProvider::class);
        config(['h5.modules.audience.ready' => true, 'billing.new_purchase_provider' => 'paypal_live', 'billing.paypal.enabled' => false, 'billing.paypal_live' => [
            'enabled' => false, 'client_id' => 'fixture-client', 'client_secret' => 'fixture-secret',
            'webhook_id' => '1AB23456CD789012E', 'merchant_scope' => 'fixture-seller',
            'merchant_id' => 'SELLERFIXTURE1', 'product_id' => self::PRODUCT,
            'webhook_url' => 'https://billing.example.test/webhooks/paypal/live',
        ]]);
        $this->app->register(AudienceServiceProvider::class);
        Route::middleware('web')->group(base_path('routes/modules/audience.php'));
        $handler = new MockHandler(array_fill(0, 300, fn ($request) => $this->respond($request)));
        $this->app->instance(LiveApi::class, new LiveApi($handler));
        $this->app->bind(RegionResolver::class, fn () => new class implements RegionResolver
        {
            public function resolve(): ResolvedRegion
            {
                return new ResolvedRegion('US', 'local_us_test');
            }
        });
        DB::table('billing_prices')->insert(['id' => BillingStore::id(), 'version' => 1,
            'regular_amount_minor' => 1200, 'first_week_amount_minor' => 300, 'currency' => 'USD',
            'is_test' => false, 'created_at' => now()]);
        DB::table('billing_paypal_merchant_bindings')->insert(['merchant_scope' => 'fixture-seller',
            'client_id_sha256' => hash('sha256', 'fixture-client'), 'merchant_id' => 'SELLERFIXTURE1',
            'environment' => 'live', 'binding_source' => 'operator_dashboard', 'evidence_ref' => 'TEST-receipt',
            'receipt_sha256' => hash('sha256', 'synthetic fixture'), 'verified_at' => now(), 'created_at' => now()]);
    }

    protected function tearDown(): void
    {
        DB::table('billing_paypal_merchant_bindings')->delete();
        DB::table('billing_paypal_observations')->delete();
        DB::table('billing_provider_resources')->delete();
        DB::table('billing_provider_intents')->delete();
        foreach (['billing_refunds', 'billing_inbox', 'billing_payments', 'billing_orders', 'billing_subscriptions'] as $table) {
            DB::table($table)->where('provider', 'paypal')->delete();
        }
        parent::tearDown();
    }

    public function test_live_http_checkout_redirects_once_without_session_token_and_rechecks_original_scope(): void
    {
        [$orderId, $returnUrl, $originalKey] = $this->startPaypalCheckout();
        $order = DB::table('billing_orders')->first();
        $this->assertSame('live', $order->provider_environment);
        $this->assertFalse($order->is_test);
        $request = json_decode($order->checkout_request, true)['params']['application_context'];
        $this->assertSame(rtrim(config('app.url'), '/').$returnUrl, $request['return_url']);
        $this->assertSame($request['return_url'], $request['cancel_url']);
        $callsBeforeReturn = count($this->calls);
        $this->get($returnUrl.'&success=true&environment=live')->assertOk()->assertSee('No active paid access')
            ->assertDontSee('BA-FIXTURE')->assertDontSee('https://www.paypal.com/');
        $this->assertSame($callsBeforeReturn, count($this->calls));
        $this->assertDatabaseCount('billing_payments', 0);
        $this->assertDatabaseCount('billing_entitlements', 0);
        config(['billing.new_purchase_provider' => 'unconfigured']);
        for ($i = 0; $i < 2; $i++) {
            $this->post('/billing/return/recheck', ['order_id' => $orderId])
                ->assertRedirect('https://www.paypal.com/webapps/billing/subscriptions?ba_token=BA-FIXTURE');
            $this->assertNull(session('audience.billing.checkouts.'.$orderId.'.checkout.checkout_url'));
        }
        $this->assertSame($originalKey, DB::table('billing_orders')->value('idempotency_key'));
        $this->assertDatabaseCount('billing_orders', 1);
        $this->assertDatabaseCount('billing_subscriptions', 1);
        $this->assertSame(1, $this->countCall('POST /v1/billing/subscriptions'));
        $this->assertDatabaseCount('billing_entitlements', 0);
        $this->assertStringNotContainsString('BA-FIXTURE', json_encode(session()->all()));
    }

    public function test_live_active_without_payment_does_not_redirect_or_grant_and_old_session_token_is_removed(): void
    {
        [$orderId, $returnUrl] = $this->startPaypalCheckout();
        $this->remote['status'] = 'ACTIVE';
        $this->remote['links'] = [];
        $this->post('/billing/return/recheck', ['order_id' => $orderId])->assertRedirect($returnUrl);
        session()->put('audience.billing.checkouts.'.$orderId.'.checkout.checkout_url', 'https://www.paypal.com/webapps/billing/subscriptions?ba_token=BA-OLD');
        $this->get($returnUrl)->assertOk()->assertSee('Payment is not yet confirmed. Check the status before trying again.')
            ->assertSee('No active paid access')->assertDontSee('BA-OLD');
        $this->assertNull(session('audience.billing.checkouts.'.$orderId.'.checkout.checkout_url'));
        $this->assertDatabaseCount('billing_payments', 0);
        $this->assertDatabaseCount('billing_entitlements', 0);
        $this->assertSame(1, $this->countCall('POST /v1/billing/subscriptions'));
    }

    public function test_live_approve_requires_exact_url_and_real_runtime_admission(): void
    {
        $controller = app(BillingController::class);
        $method = new \ReflectionMethod($controller, 'checkoutUrlIsSafe');
        $url = 'https://www.paypal.com/webapps/billing/subscriptions?ba_token=BA-FIXTURE';
        $this->assertTrue($method->invoke($controller, $url));
        foreach (['http://www.paypal.com/webapps/billing/subscriptions?ba_token=BA-FIXTURE',
            'https://www.paypal.com.evil.invalid/webapps/billing/subscriptions?ba_token=BA-FIXTURE',
            'https://user@www.paypal.com/webapps/billing/subscriptions?ba_token=BA-FIXTURE',
            'https://www.paypal.com:443/webapps/billing/subscriptions?ba_token=BA-FIXTURE',
            $url.'&return=https://evil.invalid', $url.'#fragment', str_replace('/webapps/', '/wrong/', $url),
            str_replace('BA-FIXTURE', 'BA-FIXTURE%0a', $url)] as $bad) {
            $this->assertFalse($method->invoke($controller, $bad));
        }
        $this->app->instance(LiveApi::class, new LiveApi);
        $this->assertFalse($method->invoke($controller, $url)); // No explicit HTTP Fake.
        foreach (['local', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            $this->assertFalse($method->invoke($controller, $url));
        }
        $this->app->instance('env', 'testing');
        $this->assertTrue($method->invoke($controller, str_replace('www.paypal.com', 'www.sandbox.paypal.com', $url)));
    }

    public function test_production_live_link_requires_the_existing_live_guard_not_the_new_purchase_switch(): void
    {
        $previous = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];
        $configuration = config()->all();
        $controller = app(BillingController::class);
        $method = new \ReflectionMethod($controller, 'checkoutUrlIsSafe');
        $url = 'https://www.paypal.com/webapps/billing/subscriptions?ba_token=BA-FIXTURE';
        try {
            $this->app->instance('env', 'production');
            $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'production';
            putenv('APP_ENV=production');
            config(['app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://billing.example.test',
                'h5.controlled_staging' => false, 'h5.simulation' => false, 'h5.local_us_test_region' => false,
                'h5.measurement_c_experience' => false, 'h5.measurement_c_registration' => false,
                'h5.acquisition_staging_test' => false, 'h5.local_modules' => '',
                'h5.modules.acquisition' => ['provider' => null, 'ready' => false],
                'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax',
                'trustedproxy.proxies' => null, 'billing.driver' => 'unconfigured', 'billing.new_purchase_provider' => 'unconfigured',
                'mail.default' => 'smtp', 'mail.driver' => null, 'mail.from.address' => 'noreply@example.test',
                'mail.mailers' => ['smtp' => ['transport' => 'smtp', 'scheme' => 'smtp', 'url' => null,
                    'host' => 'smtp.example.test', 'port' => '587', 'timeout' => 10,
                    'username' => 'SYNTHETIC_NOT_SENT', 'password' => 'SYNTHETIC_NOT_SENT', 'require_tls' => true, 'verify_peer' => true]],
            ]);
            $this->assertFalse($method->invoke($controller, $url)); // Live remains disabled.
            config(['billing.paypal_live.enabled' => true]);
            $this->assertTrue($method->invoke($controller, $url));
            config(['app.debug' => true]);
            $this->assertFalse($method->invoke($controller, $url));
        } finally {
            $this->app->instance('env', 'testing');
            config($configuration);
            [$server, $env, $process] = $previous;
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

    /** @return array{string, string, string} */
    private function startPaypalCheckout(): array
    {
        $viewer = $this->viewer();
        $this->actingAs(Viewer::findOrFail($viewer->id), 'web')->withSession(['identity.version.web' => 1]);
        $offer = $this->get('/membership?purchase=1')->assertOk()->viewData('offer');
        $this->post('/membership/checkout', ['offer_id' => $offer->offer_id, 'price_version' => $offer->price_version, 'confirmed' => '1'])
            ->assertRedirect('https://www.paypal.com/webapps/billing/subscriptions?ba_token=BA-FIXTURE');
        $orderId = DB::table('billing_orders')->value('id');
        $returnUrl = '/billing/return?order_id='.$orderId;
        $this->assertNull(session('audience.billing.checkouts.'.$orderId.'.checkout.checkout_url'));

        return [$orderId, $returnUrl, DB::table('billing_orders')->value('idempotency_key')];
    }

    private function countCall(string $call): int
    {
        return count(array_filter($this->calls, fn (string $actual): bool => $actual === $call));
    }

    private function respond($request): Response
    {
        $this->assertSame('https', $request->getUri()->getScheme());
        $this->assertSame('api-m.paypal.com', $request->getUri()->getHost());
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        $this->calls[] = $method.' '.$path;
        $body = json_decode((string) $request->getBody(), true) ?: [];

        $data = match (true) {
            $path === '/v1/oauth2/token' => ['access_token' => 'fixture-access', 'token_type' => 'Bearer', 'expires_in' => 3600],
            $path === '/v1/notifications/webhooks/1AB23456CD789012E' => ['id' => '1AB23456CD789012E',
                'url' => 'https://billing.example.test/webhooks/paypal/live', 'event_types' => array_map(fn ($name) => ['name' => $name], SandboxApi::REQUIRED_WEBHOOK_EVENTS)],
            $path === '/v1/catalogs/products/'.self::PRODUCT => ['id' => self::PRODUCT, 'type' => 'DIGITAL'],
            $path === '/v1/billing/plans' && $method === 'POST' => $this->plan = ['id' => self::PLAN] + $body,
            $path === '/v1/billing/plans/'.self::PLAN => $this->plan,
            $path === '/v1/billing/subscriptions' && $method === 'POST' => $this->remote = [
                'id' => self::SUBSCRIPTION, 'plan_id' => $body['plan_id'], 'custom_id' => $body['custom_id'],
                'quantity' => '1', 'plan_overridden' => false, 'status' => 'APPROVAL_PENDING',
                'links' => [['rel' => 'approve', 'method' => 'GET', 'href' => 'https://www.paypal.com/webapps/billing/subscriptions?ba_token=BA-FIXTURE']],
            ],
            $path === '/v1/billing/subscriptions/'.self::SUBSCRIPTION => $this->remote,
            str_ends_with($path, '/transactions') => ['transactions' => [], 'total_items' => 0, 'total_pages' => 1],
            default => throw new \RuntimeException('UNEXPECTED_SYNTHETIC_PAYPAL_REQUEST'),
        };

        return new Response(200, [], json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function viewer(): ViewerIdentity
    {
        $viewer = Viewer::forceCreate(['id' => BillingStore::id(), 'name' => 'Audience PayPal fixture',
            'email' => BillingStore::id().'@example.test', 'password' => bcrypt('LocalFixture9!'),
            'active' => true, 'email_verified_at' => now()]);

        return new ViewerIdentity($viewer->id, $viewer->email, true);
    }
}
