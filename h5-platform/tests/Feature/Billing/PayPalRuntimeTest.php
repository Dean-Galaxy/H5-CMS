<?php

namespace Tests\Feature\Billing;

use App\Contracts\RegionResolver;
use App\Data\CheckoutInput;
use App\Data\ResolvedRegion;
use App\Data\ViewerIdentity;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Gateway\PayPal\SandboxApi;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Services\BillingEngine;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\PayPalReconciliation;
use App\Modules\Billing\Services\WebhookService;
use App\Modules\Identity\Models\Viewer;
use App\Support\ApiProblem;
use App\Support\DependencyNotReady;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** End-to-end module calls with an explicit HTTP sandbox fixture, never official evidence. */
final class PayPalRuntimeTest extends TestCase
{
    use DatabaseMigrations;

    private const PLAN = 'P-ABCDEFGHIJKLMNOPQRSTUVWX';

    private const PRODUCT = 'PROD-ABCDEFGHIJKLMNOPQ';

    private const SALE = '123456789ABCDEFGH';

    private array $calls = [];

    private array $plan = [];

    private array $remote = [];

    private array $transactions = [];

    private array $sales = [];

    private array $refund = [];

    private bool $failSuspend = false;

    private bool $merchantUnavailable = false;

    private bool $missingPage = false;

    private bool $missingWebhookEvents = false;

    private bool $validSignature = true;

    private Viewer $user;

    private BillingEngine $billing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(BillingServiceProvider::class);
        config(['billing.new_purchase_provider' => 'paypal', 'billing.paypal' => ['enabled' => true,
            'client_id' => 'fixture-client', 'client_secret' => 'fixture-secret', 'webhook_id' => '1AB23456CD789012E',
            'merchant_scope' => 'fixture-seller', 'merchant_id' => 'SELLERFIXTURE1', 'product_id' => self::PRODUCT, 'webhook_url' => 'https://billing.example.test/webhooks/paypal']]);
        $this->travelTo(CarbonImmutable::parse('2026-10-27T06:00:00Z'));
        $handler = new MockHandler(array_fill(0, 500, fn ($request) => $this->respond($request)));
        $this->app->instance(SandboxApi::class, new SandboxApi(new Client(['handler' => HandlerStack::create($handler)])));
        $this->app->bind(RegionResolver::class, fn () => new class implements RegionResolver
        {
            public function resolve(): ResolvedRegion
            {
                return new ResolvedRegion('US', 'local_us_test');
            }
        });
        $this->user = Viewer::forceCreate(['id' => BillingStore::id(), 'name' => 'PayPal local fixture', 'email' => BillingStore::id().'@example.test',
            'password' => bcrypt('LocalFixture9!'), 'active' => true, 'email_verified_at' => now()]);
        DB::table('billing_prices')->insert(['id' => BillingStore::id(), 'version' => 1, 'regular_amount_minor' => 1200,
            'first_week_amount_minor' => 300, 'currency' => 'USD', 'is_test' => true, 'created_at' => now()]);
        // Explicit test-only operator-attested binding. This is not official identity evidence.
        DB::table('billing_paypal_merchant_bindings')->insert(['merchant_scope' => 'fixture-seller',
            'client_id_sha256' => hash('sha256', 'fixture-client'), 'merchant_id' => 'SELLERFIXTURE1',
            'environment' => 'sandbox', 'binding_source' => 'operator_dashboard', 'evidence_ref' => 'TEST-receipt',
            'receipt_sha256' => hash('sha256', 'fixture'), 'verified_at' => now(), 'created_at' => now()]);
        $this->billing = app(BillingEngine::class);
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

    public function test_checkout_webhook_reconciliation_membership_and_duplicate_delivery_share_one_ledger(): void
    {
        $order = $this->checkout();
        self::assertNull($order->expires_at);
        self::assertFalse($this->billing->membership($this->user->id, now()->toDateTimeImmutable())->access_active);
        $this->paid();
        $body = json_encode(['id' => 'WH-LOCAL-PAID', 'event_type' => 'PAYMENT.SALE.COMPLETED', 'create_time' => now()->toIso8601ZuluString(), 'resource' => ['id' => self::SALE]]);
        $headers = ['paypal-auth-algo' => 'SHA256withRSA', 'paypal-cert-url' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-fixture',
            'paypal-transmission-id' => 'fixture-delivery', 'paypal-transmission-sig' => base64_encode('fixture'), 'paypal-transmission-time' => now()->toIso8601ZuluString()];
        $this->postJson('/webhooks/paypal', json_decode($body, true), $headers)->assertOk();
        self::assertSame(1, app(WebhookService::class)->processPending());
        self::assertTrue($this->billing->membership($this->user->id, now()->toDateTimeImmutable())->access_active);
        $this->postJson('/webhooks/paypal', json_decode($body, true), $headers)->assertOk();
        self::assertSame(0, app(WebhookService::class)->processPending());
        self::assertDatabaseCount('billing_payments', 1);
        self::assertDatabaseCount('billing_entitlements', 1);
        self::assertDatabaseCount('billing_outbox', 1);
        self::assertDatabaseCount('billing_paypal_observations', 1);
        $fact = DB::table('billing_payments')->first();
        self::assertSame('2026-11-03T06:00:00Z', BillingStore::iso($fact->period_end));
    }

    public function test_cancel_requires_observed_stop_preserves_paid_period_and_disables_resume(): void
    {
        $order = $this->checkout();
        $this->paid();
        $this->sync($order);
        $this->failSuspend = true;
        $this->pending(fn () => $this->billing->cancel($this->viewer()));
        self::assertTrue(DB::table('billing_subscriptions')->first()->renewal_enabled);
        self::assertSame('pending', DB::table('billing_operations')->first()->state);
        $this->failSuspend = false;
        $result = $this->billing->cancel($this->viewer());
        self::assertFalse($result->renewal_enabled);
        self::assertTrue($result->access_active);
        self::assertSame('2026-11-03T06:00:00Z', $result->access_until);
        self::assertSame('succeeded', DB::table('billing_operations')->first()->state);
        try {
            $this->billing->resumeQuote($this->viewer());
            self::fail('Resume must be closed');
        } catch (DependencyNotReady) {
        }
        self::assertNotContains('POST /v1/billing/subscriptions/I-FIXTURE/activate', $this->calls);
    }

    public function test_renewal_uses_original_price_and_original_period_when_delivery_is_late(): void
    {
        $order = $this->checkout();
        $this->paid();
        $this->sync($order);
        DB::table('billing_prices')->insert(['id' => BillingStore::id(), 'version' => 2, 'regular_amount_minor' => 1700, 'first_week_amount_minor' => 500, 'currency' => 'USD', 'is_test' => true, 'created_at' => now()]);
        $this->travel(8)->days();
        $this->paid(2);
        $this->sync($order);
        self::assertDatabaseCount('billing_payments', 2);
        $second = DB::table('billing_payments')->orderByDesc('period_start')->first();
        self::assertSame(1200, $second->amount_minor);
        self::assertSame('2026-11-03T06:00:00Z', BillingStore::iso($second->period_start));
        self::assertSame('2026-11-10T06:00:00Z', BillingStore::iso($second->period_end));
        self::assertSame('renewal', json_decode(DB::table('billing_outbox')->where('payment_id', $second->id)->value('payload'), true)['transaction_kind']);
    }

    public function test_failed_renewal_never_adds_access_and_blocks_second_purchase(): void
    {
        $order = $this->checkout();
        $this->paid();
        $this->sync($order);
        $this->travel(8)->days();
        $this->remote['billing_info']['failed_payments_count'] = 1;
        $this->remote['billing_info']['outstanding_balance']['value'] = '12.00';
        $this->sync($order);
        $member = $this->billing->membership($this->user->id, now()->toDateTimeImmutable());
        self::assertFalse($member->access_active);
        self::assertTrue($member->payment_resolution_required);
        $this->pending(fn () => $this->checkout());
        self::assertDatabaseCount('billing_subscriptions', 1);
        self::assertDatabaseCount('billing_payments', 1);
    }

    public function test_partial_then_full_refund_only_revokes_its_payment_and_stops_renewal(): void
    {
        $order = $this->checkout();
        $this->paid();
        $this->sync($order);
        $this->travel(8)->days();
        $this->paid(2);
        $this->sync($order);
        $payment = DB::table('billing_payments')->where('provider_charge_id', self::SALE)->first();
        $this->sales[self::SALE]['state'] = 'partially_refunded';
        $this->refund = ['id' => 'REFUNDLOCAL1', 'sale_id' => self::SALE, 'amount' => ['total' => '1.00', 'currency' => 'USD'], 'state' => 'completed'];
        app(PayPalReconciliation::class)->refund(ProviderScope::fromRecord($payment), 'REFUNDLOCAL1');
        self::assertNull(DB::table('billing_entitlements')->where('payment_id', $payment->id)->value('revoked_at'));
        self::assertFalse(DB::table('billing_subscriptions')->first()->renewal_enabled);
        $this->sales[self::SALE]['state'] = 'refunded';
        app(PayPalReconciliation::class)->syncRefunds($payment->id);
        self::assertNotNull(DB::table('billing_entitlements')->where('payment_id', $payment->id)->value('revoked_at'));
        self::assertTrue($this->billing->membership($this->user->id, now()->toDateTimeImmutable())->access_active);
        self::assertSame(1, DB::table('billing_entitlements')->whereNull('revoked_at')->count());
        self::assertDatabaseCount('billing_refunds', 1); // no invented refund IDs for the missed remainder
    }

    public function test_wrong_merchant_blocks_creation_and_empty_unconfigured_cannot_buy(): void
    {
        config(['billing.paypal.merchant_id' => 'OTHERSELLER']);
        try {
            $this->checkout();
            self::fail('Wrong merchant');
        } catch (RuntimeException) {
        }
        self::assertDatabaseCount('billing_orders', 0);
        config(['billing.new_purchase_provider' => 'unconfigured']);
        try {
            $this->checkout();
            self::fail('Disabled');
        } catch (DependencyNotReady) {
        }
        self::assertDatabaseCount('billing_orders', 0);
    }

    public function test_incomplete_transaction_page_or_ambiguous_cycle_never_settles(): void
    {
        $order = $this->checkout();
        $this->paid();
        $this->missingPage = true;
        try {
            $this->sync($order);
            self::fail('Missing page');
        } catch (RuntimeException) {
        }
        self::assertDatabaseCount('billing_payments', 0);
        $this->missingPage = false;
        $this->remote['billing_info']['next_billing_time'] = '2026-11-04T06:00:00Z';
        try {
            $this->sync($order);
            self::fail('Contradictory period');
        } catch (RuntimeException) {
        }
        self::assertDatabaseCount('billing_entitlements', 0);
        self::assertTrue(DB::table('billing_subscriptions')->first()->resolution_required);
    }

    public function test_expired_unapproved_checkout_is_hard_cancelled_before_occupancy_is_released(): void
    {
        $order = $this->checkout();
        $this->travel(32)->minutes();
        $this->sync($order);
        self::assertSame('CANCELLED', $this->remote['status']);
        self::assertSame('ended', DB::table('billing_subscriptions')->first()->state);
        self::assertSame('expired', DB::table('billing_orders')->first()->state);
        self::assertNull(DB::table('billing_accounts')->first()->first_paid_at);
    }

    public function test_setup_checks_existing_binding_and_creates_one_product_without_webhook(): void
    {
        config(['billing.new_purchase_provider' => 'unconfigured', 'billing.paypal.webhook_id' => null,
            'billing.paypal.webhook_url' => null, 'billing.paypal.product_id' => null]);
        $this->artisan('billing:paypal-prepare')->expectsOutputToContain('PAYPAL_NOT_CONFIGURED')->assertExitCode(1);
        self::assertCount(0, $this->calls);
        $this->artisan('billing:paypal-prepare', ['--check-merchant' => true])
            ->expectsOutputToContain('"merchant_binding_valid":true,"binding_source":"operator_dashboard","api_merchant_identity_verified":false')->assertExitCode(0);
        $this->artisan('billing:paypal-prepare', ['--create-product' => true])
            ->expectsOutputToContain('"product_id":"'.self::PRODUCT.'"')->assertExitCode(0);
        $this->artisan('billing:paypal-prepare', ['--create-product' => true])->assertExitCode(0);
        self::assertSame(1, count(array_filter($this->calls, fn ($call) => $call === 'POST /v1/catalogs/products')));
        self::assertDatabaseCount('billing_orders', 0);
        self::assertNotContains('POST /v1/notifications/verify-webhook-signature', $this->calls);
    }

    public function test_unconfigured_new_purchase_stays_disabled_even_after_setup(): void
    {
        config(['billing.new_purchase_provider' => 'unconfigured']);
        $this->artisan('billing:paypal-prepare', ['--check-merchant' => true])->assertExitCode(0);
        try {
            $this->checkout();
            self::fail('No implicit enablement');
        } catch (DependencyNotReady) {
        }
        self::assertDatabaseCount('billing_orders', 0);
    }

    public function test_refund_correction_restores_only_original_interval_without_restoring_discount_or_renewal(): void
    {
        $order = $this->checkout();
        $this->paid();
        $this->sync($order);
        $payment = DB::table('billing_payments')->first();
        $this->sales[self::SALE]['state'] = 'refunded';
        $this->refund = ['id' => 'REFUNDLOCAL1', 'sale_id' => self::SALE, 'amount' => ['total' => '3.00', 'currency' => 'USD'], 'state' => 'completed'];
        app(PayPalReconciliation::class)->refund(ProviderScope::fromRecord($payment), 'REFUNDLOCAL1');
        self::assertNotNull(DB::table('billing_entitlements')->first()->revoked_at);
        $this->refund['state'] = 'failed';
        $this->sales[self::SALE]['state'] = 'completed';
        app(PayPalReconciliation::class)->syncRefunds($payment->id);
        self::assertNull(DB::table('billing_entitlements')->first()->revoked_at);
        self::assertSame('2026-11-03T06:00:00Z', BillingStore::iso(DB::table('billing_entitlements')->first()->access_until));
        self::assertNotNull(DB::table('billing_accounts')->first()->first_offer_used_at);
        self::assertFalse(DB::table('billing_subscriptions')->first()->renewal_enabled);
    }

    public function test_terminal_without_proof_of_no_payment_retains_unique_occupancy(): void
    {
        $order = $this->checkout();
        $this->remote['status'] = 'CANCELLED';
        $this->sync($order);
        self::assertNotSame('ended', DB::table('billing_subscriptions')->first()->state);
        self::assertTrue(DB::table('billing_subscriptions')->first()->resolution_required);
        $this->pending(fn () => $this->checkout());
        self::assertDatabaseCount('billing_subscriptions', 1);
    }

    public function test_late_cancel_event_rereads_current_provider_state_and_cannot_extend_membership(): void
    {
        $order = $this->checkout();
        $this->paid();
        $this->sync($order);
        $this->travel(8)->days();
        $this->remote['status'] = 'CANCELLED';
        $event = (object) (ProviderScope::fromRecord($order)->columns()
            + ['event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED', 'object_id' => 'I-FIXTURE']);
        app(PayPalReconciliation::class)->process($event);
        self::assertFalse($this->billing->membership($this->user->id, now()->toDateTimeImmutable())->access_active);
        self::assertSame('ended', DB::table('billing_subscriptions')->first()->state);
        self::assertDatabaseCount('billing_payments', 1);
    }

    public function test_unavailable_app_authentication_is_a_safe_diagnostic_and_cannot_create_orders(): void
    {
        $this->merchantUnavailable = true;
        $this->artisan('billing:paypal-prepare', ['--check-merchant' => true])
            ->expectsOutputToContain('PAYPAL_SETUP_UNAVAILABLE')->assertExitCode(1);
        try {
            $this->checkout();
            self::fail('No merchant proof');
        } catch (RuntimeException) {
        }
        self::assertDatabaseCount('billing_orders', 0);
        self::assertDatabaseCount('billing_provider_intents', 0);
    }

    public function test_wrong_sale_binding_amount_or_currency_cannot_grant_access(): void
    {
        $order = $this->checkout();
        foreach (['binding', 'amount', 'currency'] as $fault) {
            $this->paid();
            if ($fault === 'binding') {
                $this->sales[self::SALE]['billing_agreement_id'] = 'I-OTHER';
            } elseif ($fault === 'amount') {
                $this->sales[self::SALE]['amount']['total'] = '0.01';
            } else {
                $this->sales[self::SALE]['amount']['currency'] = 'EUR';
            }
            try {
                $this->sync($order);
                self::fail('Bad payment fact: '.$fault);
            } catch (RuntimeException) {
            }
            self::assertDatabaseCount('billing_payments', 0);
            self::assertDatabaseCount('billing_entitlements', 0);
            self::assertDatabaseCount('billing_outbox', 0);
            self::assertNull(DB::table('billing_accounts')->first()->first_offer_used_at);
        }
        $this->paid();
        $this->sync($order);
        self::assertDatabaseCount('billing_payments', 1);
    }

    public function test_pending_failed_and_cancelled_refunds_do_not_stop_or_revoke_a_completed_sale(): void
    {
        $order = $this->checkout();
        $this->paid();
        $this->sync($order);
        $payment = DB::table('billing_payments')->first();
        foreach (['pending', 'failed', 'cancelled'] as $state) {
            $this->refund = ['id' => 'REFUNDLOCAL1', 'sale_id' => self::SALE, 'amount' => ['total' => '3.00', 'currency' => 'USD'], 'state' => $state];
            app(PayPalReconciliation::class)->refund(ProviderScope::fromRecord($payment), 'REFUNDLOCAL1');
            $sub = DB::table('billing_subscriptions')->first();
            self::assertTrue($sub->renewal_enabled, $state);
            self::assertNull($sub->desired_renewal, $state); // no user stop/resume intent was introduced
            self::assertFalse($sub->refund_stopped, $state);
            self::assertNull(DB::table('billing_entitlements')->first()->revoked_at, $state);
            self::assertSame(0, DB::table('billing_payments')->first()->refunded_amount_minor, $state);
            self::assertSame($state, DB::table('billing_refunds')->first()->status);
            self::assertDatabaseCount('billing_operations', 0);
        }
        self::assertNotContains('POST /v1/billing/subscriptions/I-FIXTURE/suspend', $this->calls);
        self::assertNotContains('POST /v1/billing/subscriptions/I-FIXTURE/cancel', $this->calls);
    }

    public function test_lagging_transaction_history_cannot_release_a_terminal_subscription(): void
    {
        $order = $this->checkout();
        $this->paid();
        $this->sync($order);
        $this->travel(8)->days();
        $old = $this->transactions;
        $this->paid(2);
        $this->remote['status'] = 'CANCELLED';
        $this->transactions = $old;
        $this->sync($order);
        self::assertTrue(DB::table('billing_subscriptions')->first()->resolution_required);
        self::assertNotSame('ended', DB::table('billing_subscriptions')->first()->state);
        $this->pending(fn () => $this->checkout());
        self::assertDatabaseCount('billing_payments', 1);
    }

    public function test_reversal_stop_intent_survives_failed_read_transaction(): void
    {
        $order = $this->checkout();
        $this->paid();
        $this->sync($order);
        $this->transactions[0]['status'] = 'REVERSED';
        $this->sales[self::SALE]['state'] = 'reversed';
        try {
            $this->sync($order);
            self::fail('Reversal needs review');
        } catch (RuntimeException) {
        }
        $sub = DB::table('billing_subscriptions')->first();
        self::assertFalse($sub->desired_renewal);
        self::assertTrue($sub->resolution_required);
        self::assertDatabaseCount('billing_refunds', 0);
        self::assertNull(DB::table('billing_entitlements')->first()->revoked_at);
        $this->billing->retryPendingOperations();
        self::assertFalse(DB::table('billing_subscriptions')->first()->renewal_enabled);
    }

    public function test_incomplete_webhook_events_cannot_create_checkout(): void
    {
        $this->missingWebhookEvents = true;
        try {
            $this->checkout();
            self::fail('Incomplete event coverage');
        } catch (DependencyNotReady $e) {
            self::assertSame('DEPENDENCY_NOT_READY', $e->errorCode);
        }
        self::assertDatabaseCount('billing_orders', 0);
    }

    private function checkout(): object
    {
        $offer = $this->billing->offer($this->viewer());
        $result = $this->billing->checkout($this->viewer(), new CheckoutInput($offer->offer_id, $offer->price_version, '/membership'), BillingStore::id(), null);
        self::assertStringStartsWith('https://www.sandbox.paypal.com/', $result->checkout_url);

        return DB::table('billing_orders')->find($result->order_id);
    }

    private function viewer(): ViewerIdentity
    {
        return new ViewerIdentity($this->user->id, $this->user->email, true);
    }

    private function sync(object $order): void
    {
        app(PayPalReconciliation::class)->sync($order->subscription_id);
    }

    private function pending(callable $fn): void
    {
        try {
            $fn();
            self::fail('Expected pending');
        } catch (ApiProblem $e) {
            self::assertContains($e->errorCode, ['BILLING_PENDING', 'SUBSCRIPTION_EXISTS']);
        }
    }

    private function paid(int $count = 1): void
    {
        $start = CarbonImmutable::parse('2026-10-27T06:00:00Z');
        $this->remote['status'] = 'ACTIVE';
        $this->remote['subscriber'] = ['payer_id' => 'BUYERFIXTURE1'];
        $this->remote['start_time'] = $start->toIso8601ZuluString();
        $this->remote['billing_info'] = ['failed_payments_count' => 0, 'outstanding_balance' => ['value' => '0.00', 'currency_code' => 'USD'],
            'cycle_executions' => [['tenure_type' => 'TRIAL', 'sequence' => 1, 'total_cycles' => 1, 'cycles_completed' => 1], ['tenure_type' => 'REGULAR', 'sequence' => 2, 'total_cycles' => 0, 'cycles_completed' => $count - 1]],
            'next_billing_time' => $start->addWeeks($count)->toIso8601ZuluString(),
            'last_payment' => ['amount' => ['value' => $count === 1 ? '3.00' : '12.00', 'currency_code' => 'USD'], 'time' => $start->addWeeks($count - 1)->toIso8601ZuluString()]];
        $this->transactions = [];
        $this->sales = [];
        for ($i = 0; $i < $count; $i++) {
            $id = $i === 0 ? self::SALE : 'RENEWAL'.$i;
            $time = $start->addWeeks($i)->toIso8601ZuluString();
            $amount = $i === 0 ? '3.00' : '12.00';
            $this->transactions[] = ['id' => $id, 'status' => 'COMPLETED', 'time' => $time, 'amount_with_breakdown' => ['gross_amount' => ['value' => $amount, 'currency_code' => 'USD']]];
            $this->sales[$id] = ['id' => $id, 'billing_agreement_id' => 'I-FIXTURE', 'state' => 'completed', 'create_time' => $time, 'amount' => ['total' => $amount, 'currency' => 'USD']];
        }
    }

    private function respond($request): Response
    {
        self::assertSame('api-m.sandbox.paypal.com', $request->getUri()->getHost());
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        $this->calls[] = $method.' '.$path;
        $body = json_decode((string) $request->getBody(), true);
        if ($path === '/v1/oauth2/token' && $this->merchantUnavailable) {
            return new Response(403, [], '{"debug_id":"private-fixture-diagnostic"}');
        }
        $data = match (true) {
            $path === '/v1/oauth2/token' => ['access_token' => 'fixture-access', 'token_type' => 'Bearer', 'expires_in' => 3600],
            $path === '/v1/notifications/webhooks/1AB23456CD789012E' => ['id' => '1AB23456CD789012E', 'url' => 'https://billing.example.test/webhooks/paypal', 'event_types' => array_map(fn ($name) => ['name' => $name], $this->missingWebhookEvents ? ['PAYMENT.SALE.COMPLETED'] : SandboxApi::REQUIRED_WEBHOOK_EVENTS)],
            $path === '/v1/catalogs/products' && $method === 'POST' => ['id' => self::PRODUCT, 'type' => 'DIGITAL'],
            str_starts_with($path, '/v1/catalogs/products/') => ['id' => self::PRODUCT, 'type' => 'DIGITAL'],
            $path === '/v1/billing/plans' && $method === 'POST' => $this->plan = ['id' => self::PLAN] + $body,
            $path === '/v1/billing/plans/'.self::PLAN => $this->plan,
            $path === '/v1/billing/subscriptions' && $method === 'POST' => $this->remote = ['id' => 'I-FIXTURE', 'plan_id' => $body['plan_id'], 'custom_id' => $body['custom_id'],
                'quantity' => '1', 'plan_overridden' => false, 'status' => 'APPROVAL_PENDING', 'links' => [['rel' => 'approve', 'method' => 'GET', 'href' => 'https://www.sandbox.paypal.com/webapps/billing/subscriptions?ba_token=BA-FIXTURE']]],
            $path === '/v1/billing/subscriptions/I-FIXTURE' => $this->remote,
            str_ends_with($path, '/transactions') => ['transactions' => $this->transactions, 'total_items' => count($this->transactions), 'total_pages' => $this->missingPage ? 2 : 1],
            str_starts_with($path, '/v1/payments/sale/') => $this->sales[basename($path)] ?? [],
            str_starts_with($path, '/v1/payments/refund/') => $this->refund,
            $path === '/v1/notifications/verify-webhook-signature' => ['verification_status' => $this->validSignature ? 'SUCCESS' : 'FAILURE'],
            str_ends_with($path, '/suspend') || str_ends_with($path, '/cancel') => [],
            default => throw new RuntimeException('UNEXPECTED_FIXTURE_REQUEST'),
        };
        if (str_ends_with($path, '/suspend')) {
            if ($this->failSuspend) {
                return new Response(500);
            }
            $this->remote['status'] = 'SUSPENDED';

            return new Response(204);
        }
        if (str_ends_with($path, '/cancel')) {
            if ($this->remote['status'] === 'APPROVAL_PENDING') {
                $this->remote['billing_info'] = ['cycle_executions' => [['cycles_completed' => 0]], 'failed_payments_count' => 0,
                    'outstanding_balance' => ['value' => '0.00', 'currency_code' => 'USD']];
            }
            $this->remote['status'] = 'CANCELLED';

            return new Response(204);
        }

        return new Response(200, [], json_encode($data));
    }
}
