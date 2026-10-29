<?php

namespace Tests\Feature\Audience;

use App\Modules\Audience\AudienceServiceProvider;
use App\Contracts\RegionResolver;
use App\Data\ResolvedRegion;
use App\Data\ViewerIdentity;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Gateway\PayPal\SandboxApi;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Identity\Models\Viewer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/** Audience consumer checks against the fixed Billing implementation and a synthetic PayPal transport. */
final class PayPalBillingReturnUiTest extends TestCase
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
        config(['h5.modules.audience.ready' => true, 'billing.new_purchase_provider' => 'paypal', 'billing.paypal' => [
            'enabled' => true, 'client_id' => 'fixture-client', 'client_secret' => 'fixture-secret',
            'webhook_id' => '1AB23456CD789012E', 'merchant_scope' => 'fixture-seller',
            'merchant_id' => 'SELLERFIXTURE1', 'product_id' => self::PRODUCT,
            'webhook_url' => 'https://billing.example.test/webhooks/paypal',
        ]]);
        $this->app->register(AudienceServiceProvider::class);
        Route::middleware('web')->group(base_path('routes/modules/audience.php'));
        $handler = new MockHandler(array_fill(0, 300, fn ($request) => $this->respond($request)));
        $this->app->instance(SandboxApi::class, new SandboxApi(new Client(['handler' => HandlerStack::create($handler)])));
        $this->app->bind(RegionResolver::class, fn () => new class implements RegionResolver
        {
            public function resolve(): ResolvedRegion
            {
                return new ResolvedRegion('US', 'local_us_test');
            }
        });
        DB::table('billing_prices')->insert(['id' => BillingStore::id(), 'version' => 1,
            'regular_amount_minor' => 1200, 'first_week_amount_minor' => 300, 'currency' => 'USD',
            'is_test' => true, 'created_at' => now()]);
        DB::table('billing_paypal_merchant_bindings')->insert(['merchant_scope' => 'fixture-seller',
            'client_id_sha256' => hash('sha256', 'fixture-client'), 'merchant_id' => 'SELLERFIXTURE1',
            'environment' => 'sandbox', 'binding_source' => 'operator_dashboard', 'evidence_ref' => 'TEST-receipt',
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

    public function test_active_but_unreconciled_checkout_stays_on_the_original_order_without_an_approval_link(): void
    {
        [$orderId, $returnUrl] = $this->startPaypalCheckout();
        $this->remote['status'] = 'ACTIVE';
        $this->remote['links'] = [];

        $this->post('/billing/return/recheck', ['order_id' => $orderId])->assertRedirect($returnUrl);
        $this->get($returnUrl)->assertOk()->assertSee('Payment is not yet confirmed. Check the status before trying again.')
            ->assertSee('Recheck this original payment')->assertSee('No active paid access')
            ->assertDontSee('Open secure payment page')->assertDontSee('https://www.sandbox.paypal.com/', false);
        $this->assertSame($orderId, DB::table('billing_orders')->value('id'));
        $this->assertSame('pending', DB::table('billing_orders')->value('state'));
        $this->assertDatabaseCount('billing_entitlements', 0);
        $this->assertSame(1, $this->countCall('POST /v1/billing/subscriptions'));
    }

    public function test_repeated_explicit_recheck_reuses_one_order_and_never_creates_another_paypal_subscription(): void
    {
        [$orderId, $returnUrl, $originalKey] = $this->startPaypalCheckout();
        $this->remote['status'] = 'ACTIVE';
        $this->remote['links'] = [];

        $this->post('/billing/return/recheck', ['order_id' => $orderId])->assertRedirect($returnUrl);
        $this->post('/billing/return/recheck', ['order_id' => $orderId])->assertRedirect($returnUrl);

        $order = DB::table('billing_orders')->first();
        $this->assertSame($orderId, $order->id);
        $this->assertSame($originalKey, $order->idempotency_key);
        $this->assertDatabaseCount('billing_orders', 1);
        $this->assertDatabaseCount('billing_subscriptions', 1);
        $this->assertSame(1, $this->countCall('POST /v1/billing/subscriptions'));
    }

    public function test_get_refresh_only_reads_the_original_local_order_without_provider_creation_or_approval_navigation(): void
    {
        [$orderId, $returnUrl] = $this->startPaypalCheckout();
        $this->remote['status'] = 'ACTIVE';
        $this->remote['links'] = [];
        $this->post('/billing/return/recheck', ['order_id' => $orderId])->assertRedirect($returnUrl);
        $callsBeforeRefresh = count($this->calls);

        $this->get($returnUrl)->assertOk()->assertSee('Recheck this original payment')
            ->assertDontSee('Open secure payment page')->assertDontSee('https://www.sandbox.paypal.com/', false);

        $this->assertSame($callsBeforeRefresh, count($this->calls));
        $this->assertSame($orderId, DB::table('billing_orders')->value('id'));
        $this->assertDatabaseCount('billing_orders', 1);
        $this->assertSame(1, $this->countCall('POST /v1/billing/subscriptions'));
    }

    /** @return array{string, string, string} */
    private function startPaypalCheckout(): array
    {
        $viewer = $this->viewer();
        $this->actingAs(Viewer::findOrFail($viewer->id), 'web')->withSession(['identity.version.web' => 1]);
        $offer = $this->get('/membership?purchase=1')->assertOk()->viewData('offer');
        $this->post('/membership/checkout', ['offer_id' => $offer->offer_id, 'price_version' => $offer->price_version, 'confirmed' => '1'])
            ->assertRedirect('https://www.sandbox.paypal.com/webapps/billing/subscriptions?ba_token=BA-FIXTURE');
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
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        $this->calls[] = $method.' '.$path;
        $body = json_decode((string) $request->getBody(), true) ?: [];

        $data = match (true) {
            $path === '/v1/oauth2/token' => ['access_token' => 'fixture-access', 'token_type' => 'Bearer', 'expires_in' => 3600],
            $path === '/v1/notifications/webhooks/1AB23456CD789012E' => ['id' => '1AB23456CD789012E',
                'url' => 'https://billing.example.test/webhooks/paypal', 'event_types' => array_map(fn ($name) => ['name' => $name], SandboxApi::REQUIRED_WEBHOOK_EVENTS)],
            $path === '/v1/catalogs/products/'.self::PRODUCT => ['id' => self::PRODUCT, 'type' => 'DIGITAL'],
            $path === '/v1/billing/plans' && $method === 'POST' => $this->plan = ['id' => self::PLAN] + $body,
            $path === '/v1/billing/plans/'.self::PLAN => $this->plan,
            $path === '/v1/billing/subscriptions' && $method === 'POST' => $this->remote = [
                'id' => self::SUBSCRIPTION, 'plan_id' => $body['plan_id'], 'custom_id' => $body['custom_id'],
                'quantity' => '1', 'plan_overridden' => false, 'status' => 'APPROVAL_PENDING',
                'links' => [['rel' => 'approve', 'method' => 'GET', 'href' => 'https://www.sandbox.paypal.com/webapps/billing/subscriptions?ba_token=BA-FIXTURE']],
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
