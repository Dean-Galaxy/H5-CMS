<?php

namespace Tests\Feature\Billing;

use App\Contracts\RegionResolver;
use App\Data\CheckoutInput;
use App\Data\PaymentSettled;
use App\Data\ResolvedRegion;
use App\Data\ViewerIdentity;
use App\Enums\Role;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Filament\Pages\BillingDesk;
use App\Modules\Billing\Gateway\PayPal\LiveApi;
use App\Modules\Billing\Gateway\PayPal\SandboxApi;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Services\BillingAdministration;
use App\Modules\Billing\Services\BillingEngine;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\OutboxService;
use App\Modules\Billing\Services\PayPalReconciliation;
use App\Modules\Billing\Services\ProviderInboxStore;
use App\Modules\Billing\Services\WebhookService;
use App\Modules\Identity\Models\Staff;
use App\Modules\Identity\Models\Viewer;
use App\Support\ApiProblem;
use App\Support\DependencyNotReady;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** Explicit Live HTTP Fake only; never a real provider connection or official payment evidence. */
final class PayPalLiveTest extends TestCase
{
    use DatabaseMigrations;

    protected const PLAN = 'P-ABCDEFGHIJKLMNOPQRSTUVWX';

    protected const PRODUCT = 'PROD-ABCDEFGHIJKLMNOPQ';

    protected const SALE = '123456789ABCDEFGH';

    protected array $calls = [];

    protected array $plan = [];

    protected array $remote = [];

    protected array $transactions = [];

    protected array $sales = [];

    protected array $refund = [];

    protected array $refunds = [];

    protected bool $failSuspend = false;

    protected bool $merchantUnavailable = false;

    protected bool $missingPage = false;

    protected ?array $pageMetadata = null;

    protected int $unstableReads = -1;

    protected bool $subscriptionUnavailable = false;

    protected bool $missingWebhookEvents = false;

    protected bool $validSignature = true;

    protected Viewer $user;

    protected BillingEngine $billing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(BillingServiceProvider::class);
        config(['billing.new_purchase_provider' => 'paypal_live', 'billing.paypal_live' => ['enabled' => false,
            'client_id' => 'fixture-client', 'client_secret' => 'fixture-secret', 'webhook_id' => '1AB23456CD789012E',
            'merchant_scope' => 'fixture-seller', 'merchant_id' => 'SELLERFIXTURE1', 'product_id' => self::PRODUCT, 'webhook_url' => 'https://billing.example.test/webhooks/paypal/live']]);
        $this->travelTo(CarbonImmutable::parse('2026-10-27T06:00:00Z'));
        $handler = new MockHandler(array_fill(0, 500, fn ($request) => $this->respond($request)));
        $this->app->instance(LiveApi::class, new LiveApi($handler));
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
            'first_week_amount_minor' => 300, 'currency' => 'USD', 'is_test' => false, 'created_at' => now()]);
        // Explicit test-only operator-attested binding. This is not official identity evidence.
        DB::table('billing_paypal_merchant_bindings')->insert(['merchant_scope' => 'fixture-seller',
            'client_id_sha256' => hash('sha256', 'fixture-client'), 'merchant_id' => 'SELLERFIXTURE1',
            'environment' => 'live', 'binding_source' => 'operator_dashboard', 'evidence_ref' => 'TEST-receipt',
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
        $body = json_encode(['id' => 'WH-LOCAL-PAID', 'event_type' => 'PAYMENT.SALE.COMPLETED', 'create_time' => now()->format('Y-m-d\TH:i:s').'.936Z', 'resource' => ['id' => self::SALE]]);
        $headers = ['paypal-auth-algo' => 'SHA256withRSA', 'paypal-cert-url' => 'https://api.paypal.com/v1/notifications/certs/CERT-fixture',
            'paypal-transmission-id' => 'fixture-delivery', 'paypal-transmission-sig' => base64_encode('fixture'), 'paypal-transmission-time' => now()->toIso8601ZuluString()];
        $this->postJson('/webhooks/paypal/live', json_decode($body, true), $headers)->assertOk();
        self::assertSame(1, app(WebhookService::class)->processPending());
        self::assertTrue($this->billing->membership($this->user->id, now()->toDateTimeImmutable())->access_active);
        $this->postJson('/webhooks/paypal/live', json_decode($body, true), $headers)->assertOk();
        self::assertSame(0, app(WebhookService::class)->processPending());
        self::assertDatabaseCount('billing_payments', 1);
        self::assertDatabaseCount('billing_entitlements', 1);
        self::assertDatabaseCount('billing_outbox', 1);
        self::assertDatabaseCount('billing_paypal_observations', 1);
        $fact = DB::table('billing_payments')->first();
        self::assertSame('2026-11-03T06:00:00Z', BillingStore::iso($fact->period_end));
    }

    public function test_live_first_billing_boundary_accepts_a_later_time_on_the_weekly_date(): void
    {
        $order = $this->checkout();
        $this->paid();
        $paidAt = '2026-10-27T06:00:58Z';
        $this->transactions[0]['time'] = '2026-10-27T06:00:58.000Z';
        $this->sales[self::SALE]['create_time'] = $this->remote['billing_info']['last_payment']['time'] = $paidAt;
        $this->travel(2)->minutes();
        $this->remote['billing_info']['next_billing_time'] = '2026-11-04T00:00:00Z';
        try {
            $this->sync($order);
            self::fail('An extra billing date must be rejected');
        } catch (RuntimeException $error) {
            self::assertSame('PAYPAL_RECONCILIATION_REQUIRED', $error->getMessage());
        }
        self::assertDatabaseCount('billing_payments', 0);
        self::assertDatabaseCount('billing_entitlements', 0);

        $firstBoundary = '2026-11-03T07:41:13Z';
        $this->remote['billing_info']['next_billing_time'] = $firstBoundary;
        $this->sync($order);
        $this->sync($order);
        self::assertDatabaseCount('billing_payments', 1);
        self::assertDatabaseCount('billing_entitlements', 1);
        self::assertSame($firstBoundary, $this->billing->membership($this->user->id, now()->toDateTimeImmutable())->access_until);

        $this->travel(8)->days();
        $this->paid(2);
        $this->transactions[0]['time'] = $this->sales[self::SALE]['create_time'] = $paidAt;
        $this->transactions[1]['time'] = $this->sales['RENEWAL1']['create_time'] = $firstBoundary;
        $this->remote['billing_info']['last_payment']['time'] = $firstBoundary;
        $this->remote['billing_info']['next_billing_time'] = '2026-11-10T07:41:13Z';
        $this->sync($order);
        self::assertDatabaseCount('billing_payments', 2);
        $renewal = DB::table('billing_payments')->orderByDesc('period_start')->first();
        self::assertSame($firstBoundary, BillingStore::iso($renewal->period_start));
        self::assertSame('2026-11-10T07:41:13Z', BillingStore::iso($renewal->period_end));
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
        DB::table('billing_prices')->insert(['id' => BillingStore::id(), 'version' => 2, 'regular_amount_minor' => 1700, 'first_week_amount_minor' => 500, 'currency' => 'USD', 'is_test' => false, 'created_at' => now()]);
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

    public function test_live_flags_are_persisted_atomically_and_consumer_failure_cannot_undo_payment(): void
    {
        $order = $this->checkout();
        $this->paid();
        $this->sync($order);
        foreach (['billing_orders', 'billing_subscriptions', 'billing_payments'] as $table) {
            $row = DB::table($table)->first();
            self::assertSame('live', $row->provider_environment);
            self::assertFalse((bool) $row->is_test);
        }
        $payload = BillingStore::json(DB::table('billing_outbox')->first()->payload);
        self::assertFalse($payload['is_test']);
        self::assertSame('first_subscription', $payload['transaction_kind']);
        PaymentSettled::fromArray($payload);
        app('events')->listen(PaymentSettled::class, fn () => throw new RuntimeException('FIXTURE_CONSUMER_DOWN'));
        self::assertSame(0, app(OutboxService::class)->dispatchPending());
        self::assertDatabaseCount('billing_payments', 1);
        self::assertDatabaseCount('billing_entitlements', 1);
        self::assertNull(DB::table('billing_outbox')->first()->delivered_at);
        self::assertTrue($this->billing->membership($this->user->id, now()->toDateTimeImmutable())->access_active);
    }

    public function test_prices_bind_the_environment_and_cannot_reuse_a_stale_test_offer(): void
    {
        config(['billing.new_purchase_provider' => 'unconfigured']);
        DB::table('billing_prices')->insert(['id' => BillingStore::id(), 'version' => 2, 'regular_amount_minor' => 9900,
            'first_week_amount_minor' => 100, 'currency' => 'USD', 'is_test' => true, 'created_at' => now()]);
        $test = $this->billing->offer($this->viewer());
        config(['billing.new_purchase_provider' => 'paypal_live']);
        self::assertSame(1, $this->billing->offer($this->viewer())->price_version);
        try {
            $this->billing->checkout($this->viewer(), new CheckoutInput($test->offer_id, $test->price_version, '/membership'), BillingStore::id(), null);
            self::fail('A test Offer cannot become a Live order');
        } catch (ApiProblem $e) {
            self::assertSame('STALE_VERSION', $e->errorCode);
        }
        self::assertDatabaseCount('billing_orders', 0);
        DB::table('billing_prices')->where('is_test', false)->delete();
        $this->expectException(DependencyNotReady::class);
        $this->billing->offer($this->viewer());
    }

    public function test_new_purchase_switch_does_not_reroute_an_original_live_order_or_cancellation(): void
    {
        $order = $this->checkout();
        config(['billing.new_purchase_provider' => 'unconfigured', 'billing.paypal.enabled' => false]);
        $this->paid();
        $this->sync($order);
        $member = $this->billing->cancel($this->viewer());
        self::assertTrue($member->access_active);
        self::assertFalse($member->renewal_enabled);
        self::assertSame('SUSPENDED', $this->remote['status']);
        self::assertDatabaseCount('billing_payments', 1);
    }

    public function test_default_live_transport_is_unavailable_without_an_explicit_http_fake(): void
    {
        $this->app->instance(LiveApi::class, new LiveApi);
        $this->expectException(DependencyNotReady::class);
        app(LiveApi::class)->product(self::PRODUCT);
    }

    public function test_callback_environment_and_signature_are_checked_before_inbox_creation(): void
    {
        $body = ['id' => 'WH-ISOLATION', 'event_type' => 'PAYMENT.SALE.COMPLETED', 'create_time' => now()->toIso8601ZuluString(), 'resource' => ['id' => self::SALE]];
        $headers = ['paypal-auth-algo' => 'SHA256withRSA', 'paypal-cert-url' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-fixture',
            'paypal-transmission-id' => 'fixture-delivery', 'paypal-transmission-sig' => base64_encode('fixture'), 'paypal-transmission-time' => now()->toIso8601ZuluString()];
        $this->postJson('/webhooks/paypal/live', $body, $headers)->assertStatus(400);
        self::assertSame([], $this->calls);
        $headers['paypal-cert-url'] = 'https://api.paypal.com/v1/notifications/certs/CERT-fixture';
        $this->validSignature = false;
        $this->postJson('/webhooks/paypal/live', $body, $headers)->assertStatus(400);
        self::assertDatabaseCount('billing_inbox', 0);
        self::assertDatabaseCount('billing_payments', 0);
        self::assertDatabaseCount('billing_entitlements', 0);
        self::assertSame(0, preg_match('~sandbox~', implode(' ', $this->calls)));
    }

    public function test_wrong_environment_binding_cannot_be_used_as_live_admission(): void
    {
        DB::table('billing_paypal_merchant_bindings')->update(['environment' => 'sandbox']);
        $this->expectException(DependencyNotReady::class);
        $this->checkout();
    }

    public function test_live_webhook_cannot_settle_an_order_in_another_persisted_scope(): void
    {
        $order = $this->checkout();
        $this->paid();
        app(ProviderInboxStore::class)->receive(new ProviderScope('paypal', 'sandbox', 'other-seller'), [
            'id' => 'WH-CROSS-ENV', 'type' => 'PAYMENT.SALE.COMPLETED', 'object_id' => self::SALE,
            'created' => now()->toIso8601ZuluString(), 'is_test' => true,
        ]);
        config(['billing.paypal.enabled' => true, 'billing.paypal.merchant_scope' => 'other-seller']);
        $this->app->instance(SandboxApi::class, new SandboxApi(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(503)]))])));
        self::assertSame(0, app(WebhookService::class)->processPending());
        self::assertDatabaseCount('billing_payments', 0);
        self::assertDatabaseCount('billing_entitlements', 0);
        self::assertSame('pending', DB::table('billing_inbox')->first()->state);
        self::assertFalse($this->billing->membership($this->user->id, now()->toDateTimeImmutable())->access_active);
    }

    public function test_explicit_live_preparation_uses_only_live_product_and_readiness(): void
    {
        $this->artisan('billing:paypal-prepare', ['--environment' => 'live', '--binding-context' => true])
            ->expectsOutputToContain('"environment":"live"')->assertExitCode(0);
        self::assertSame([], $this->calls);
        $this->artisan('billing:paypal-prepare', ['--environment' => 'live', '--create-product' => true])->assertExitCode(0);
        $this->artisan('billing:paypal-prepare', ['--environment' => 'live', '--check-readiness' => true])
            ->expectsOutputToContain('"purchase_readiness_valid":true')->assertExitCode(0);
        self::assertDatabaseCount('billing_orders', 0);
        $intent = DB::table('billing_provider_intents')->where('operation', 'create_product')->first();
        self::assertSame('live', $intent->provider_environment);
        self::assertSame('H5 weekly membership', BillingStore::json($intent->request_payload)['name']);
    }

    private function liveStaff(Role $role = Role::Owner): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $session = app('session.store');
        $this->app->rebinding('request', static function ($app, $request) use ($session): void {
            $request->setLaravelSession($session);
        });
        $staff = Staff::create(['name' => 'Live price fixture', 'email' => BillingStore::id().'@example.test', 'password' => 'TestPassword1234', 'role' => $role]);
        $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $staff->refresh();
        $this->actingAs($staff, 'staff')->withSession(['identity.version.staff' => $staff->session_version, 'identity.mfa_staff' => $staff->id]);
        $session->put('identity.version.staff', $staff->session_version);
        $session->put('identity.mfa_staff', $staff->id);
        $session->save();
        request()->setLaravelSession($session);

        return $staff;
    }

    public function test_live_owner_configures_first_price_in_existing_form_without_inheriting_test_samples(): void
    {
        $staff = $this->liveStaff();
        DB::table('billing_prices')->delete();
        DB::table('billing_prices')->insert(['id' => BillingStore::id(), 'version' => 9, 'regular_amount_minor' => 1200,
            'first_week_amount_minor' => 300, 'currency' => 'USD', 'is_test' => true, 'created_at' => now()]);
        $page = Livewire::test(BillingDesk::class)->call('selectTab', 'prices')
            ->assertSet('price.regular_amount_usd', '')->assertSet('price.first_week_amount_usd', '')
            ->assertSet('price.is_test', false)->assertSet('price.confirm_live_price', false)->assertSet('price.version', 9)
            ->assertSee('正式价格 · 真钱收费')->assertSee('保存正式价格')->assertDontSee('保存测试价格');
        self::assertSame([], $this->calls, 'Merely viewing the price form performs no provider I/O');
        $page->set('price.regular_amount_usd', '14.25')->set('price.first_week_amount_usd', '4.75')
            ->call('savePrice')->assertReturned(false)->assertHasErrors('price.confirm_live_price');
        self::assertSame([], $this->calls);
        $page->set('price.confirm_live_price', true)->call('savePrice')->assertReturned(true)
            ->assertSet('price.version', 10)->assertSet('price.confirm_live_price', false);
        self::assertDatabaseHas('billing_prices', ['version' => 10, 'regular_amount_minor' => 1425, 'first_week_amount_minor' => 475, 'is_test' => false]);
        self::assertDatabaseHas('billing_prices', ['version' => 9, 'regular_amount_minor' => 1200, 'is_test' => true]);
        self::assertDatabaseHas('identity_audit_events', ['action' => 'billing.price.created', 'actor_kind' => 'staff', 'actor_id' => $staff->id]);
        Livewire::test(BillingDesk::class)
            ->assertSet('price.regular_amount_usd', '14.25')->assertSet('price.first_week_amount_usd', '4.75')->assertSet('price.is_test', false);
        self::assertDatabaseCount('billing_orders', 0);
    }

    public function test_live_form_keeps_draft_on_admission_failure_or_stale_version_and_cannot_save_a_test_price(): void
    {
        $this->liveStaff();
        $page = Livewire::test(BillingDesk::class)->call('selectTab', 'prices')
            ->set('price.regular_amount_usd', '18.00')->set('price.first_week_amount_usd', '6.00')->set('price.confirm_live_price', true);
        $page->set('price.is_test', true)->call('savePrice')->assertReturned(false)->assertHasErrors('price.is_test');
        $this->missingWebhookEvents = true;
        $page->set('price.is_test', false)->call('savePrice')->assertReturned(false)->assertHasErrors('billing')
            ->assertSet('price.regular_amount_usd', '18.00')->assertSet('price.confirm_live_price', true);
        self::assertDatabaseCount('billing_prices', 1);
        $this->missingWebhookEvents = false;
        DB::table('billing_prices')->insert(['id' => BillingStore::id(), 'version' => 2, 'regular_amount_minor' => 1500,
            'first_week_amount_minor' => 500, 'currency' => 'USD', 'is_test' => false, 'created_at' => now()]);
        $page->call('savePrice')->assertReturned(false)->assertHasErrors('billing')->assertSet('price.regular_amount_usd', '18.00')->assertSet('price.version', 1);
        self::assertDatabaseCount('billing_prices', 2);
    }

    public function test_support_and_owner_without_mfa_cannot_save_live_price(): void
    {
        $this->liveStaff(Role::Support);
        $input = ['version' => 1, 'regular_amount_usd' => '15.00', 'first_week_amount_usd' => '5.00', 'is_test' => false, 'confirm_live_price' => true];
        try {
            app(BillingAdministration::class)->savePriceUsd($input);
            self::fail('Support cannot change prices');
        } catch (ApiProblem $e) {
            self::assertSame('NOT_AUTHORIZED', $e->errorCode);
        }
        $staff = $this->liveStaff();
        app('session.store')->forget('identity.mfa_staff');
        try {
            app(BillingAdministration::class)->savePriceUsd($input);
            self::fail('Owner MFA required');
        } catch (ApiProblem $e) {
            self::assertSame('MFA_REQUIRED', $e->errorCode);
        }
        self::assertDatabaseCount('billing_prices', 1);
        self::assertSame([], $this->calls);
    }

    private function checkout(): object
    {
        $offer = $this->billing->offer($this->viewer());
        $result = $this->billing->checkout($this->viewer(), new CheckoutInput($offer->offer_id, $offer->price_version, '/membership'), BillingStore::id(), null);
        self::assertStringStartsWith('https://www.paypal.com/', $result->checkout_url);

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
        self::assertSame('api-m.paypal.com', $request->getUri()->getHost());
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        $this->calls[] = $method.' '.$path;
        $body = json_decode((string) $request->getBody(), true);
        if ($path === '/v1/oauth2/token' && $this->merchantUnavailable) {
            return new Response(403, [], '{"debug_id":"private-fixture-diagnostic"}');
        }
        if ($path === '/v1/billing/subscriptions/I-FIXTURE') {
            if ($this->subscriptionUnavailable) {
                return new Response(503);
            }
            if ($this->unstableReads >= 0 && ++$this->unstableReads === 2) {
                $this->remote['billing_info']['last_payment']['amount']['value'] = '4.00';
            }
        }
        $data = match (true) {
            $path === '/v1/oauth2/token' => ['access_token' => 'fixture-access', 'token_type' => 'Bearer', 'expires_in' => 3600],
            $path === '/v1/notifications/webhooks/1AB23456CD789012E' => ['id' => '1AB23456CD789012E', 'url' => 'https://billing.example.test/webhooks/paypal/live', 'event_types' => array_map(fn ($name) => ['name' => $name], $this->missingWebhookEvents ? ['PAYMENT.SALE.COMPLETED'] : SandboxApi::REQUIRED_WEBHOOK_EVENTS)],
            $path === '/v1/catalogs/products' && $method === 'POST' => ['id' => self::PRODUCT, 'type' => 'DIGITAL'],
            str_starts_with($path, '/v1/catalogs/products/') => ['id' => self::PRODUCT, 'type' => 'DIGITAL'],
            $path === '/v1/billing/plans' && $method === 'POST' => $this->plan = ['id' => self::PLAN] + $body,
            $path === '/v1/billing/plans/'.self::PLAN => $this->plan,
            $path === '/v1/billing/subscriptions' && $method === 'POST' => $this->remote = ['id' => 'I-FIXTURE', 'plan_id' => $body['plan_id'], 'custom_id' => $body['custom_id'],
                'quantity' => '1', 'plan_overridden' => false, 'status' => 'APPROVAL_PENDING', 'links' => [['rel' => 'approve', 'method' => 'GET', 'href' => 'https://www.paypal.com/webapps/billing/subscriptions?ba_token=BA-FIXTURE']]],
            $path === '/v1/billing/subscriptions/I-FIXTURE' => $this->remote,
            str_ends_with($path, '/transactions') => ['transactions' => $this->transactions] + ($this->pageMetadata ?? ['total_items' => count($this->transactions), 'total_pages' => $this->missingPage ? 2 : 1]),
            str_starts_with($path, '/v1/payments/sale/') => $this->sales[basename($path)] ?? [],
            str_starts_with($path, '/v1/payments/refund/') => $this->refunds[basename($path)] ?? $this->refund,
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
