<?php

namespace Tests\Feature\Billing;

use App\Data\PaymentSettled;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\OutboxService;
use App\Modules\Billing\Services\SettlementService;
use App\Modules\Billing\Services\WebhookService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class BillingSettlementTest extends TestCase
{
    use RefreshDatabase;

    private object $gateway;

    private SettlementService $settlement;

    private string $viewerId;

    private string $subscriptionId;

    private string $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        app()->register(BillingServiceProvider::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-07T12:00:00Z'));
        $this->gateway = new class implements PaymentGateway
        {
            public array $invoice = [];

            public array $subscription = [];

            public array $refundList = [];

            public array $event = [];

            public array $session = [];

            public bool $unavailable = false;

            public bool $invoiceUnavailable = false;

            public bool $badSignature = false;

            public bool $failRenewal = false;

            public int $renewalCalls = 0;

            public function assertAvailable(): void {}

            public function createCheckout(object $order, object $subscription): array
            {
                throw new RuntimeException('Not used');
            }

            public function checkout(string $sessionId): array
            {
                return $this->session;
            }

            public function subscription(string $id): array
            {
                if ($this->unavailable) {
                    throw new RuntimeException('Provider unavailable');
                }

return $this->subscription;
            }

            public function setRenewal(object $subscription, bool $enabled, string $idempotencyKey): array
            {
                $this->renewalCalls++;
                if ($this->failRenewal) {
                    throw new RuntimeException('Provider timeout');
                }
                $this->subscription['cancel_at_period_end'] = ! $enabled;

                return $this->subscription;
            }

            public function invoice(string $id): array
            {
                if ($this->unavailable || $this->invoiceUnavailable) {
                    throw new RuntimeException('Provider unavailable');
                }

return $this->invoice;
            }

            public function refunds(string $chargeId): array
            {
                return $this->refundList;
            }

            public function verifyEvent(string $payload, string $signature): array
            {
                if ($this->badSignature) {
                    throw new RuntimeException('Signature rejected');
                }

return $this->event;
            }

            public function invoices(string $subscriptionId): array
            {
                return [$this->invoice['id']];
            }
        };
        app()->instance(PaymentGateway::class, $this->gateway);
        $this->settlement = new SettlementService($this->gateway);
        $this->viewerId = BillingStore::id();
        $this->subscriptionId = BillingStore::id();
        $this->orderId = BillingStore::id();
        DB::table('billing_accounts')->insert(['viewer_id' => $this->viewerId]);
        DB::table('billing_subscriptions')->insert([
            'id' => $this->subscriptionId, 'viewer_id' => $this->viewerId, 'first_order_id' => $this->orderId,
            'provider_id' => 'sub_test', 'customer_id' => 'cus_test', 'state' => 'pending', 'price_version' => 1,
            'regular_amount_minor' => 1200, 'first_week_amount_minor' => 300, 'currency' => 'USD', 'is_test' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('billing_orders')->insert([
            'id' => $this->orderId, 'viewer_id' => $this->viewerId, 'subscription_id' => $this->subscriptionId,
            'idempotency_key' => 'initial', 'fingerprint' => hash('sha256', 'initial'), 'transaction_kind' => 'first_subscription',
            'price_version' => 1, 'amount_minor' => 300, 'regular_amount_minor' => 1200, 'discount_applied' => true,
            'currency' => 'USD', 'is_test' => true, 'state' => 'pending', 'checkout_id' => 'cs_test', 'return_to' => '/',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->gateway->subscription = [
            'id' => 'sub_test', 'customer_id' => 'cus_test', 'status' => 'active', 'cancel_at_period_end' => false,
            'period_start' => '2026-10-06T12:00:00Z', 'period_end' => '2026-10-13T12:00:00Z',
            'regular_amount_minor' => 1200, 'currency' => 'USD', 'order_id' => $this->orderId,
            'latest_invoice_id' => 'in_test', 'is_test' => true,
        ];
        $this->gateway->invoice = [
            'id' => 'in_test', 'subscription_id' => 'sub_test', 'customer_id' => 'cus_test', 'order_id' => $this->orderId,
            'amount_minor' => 300, 'currency' => 'USD', 'period_start' => '2026-10-06T12:00:00Z', 'period_end' => '2026-10-13T12:00:00Z',
            'paid_at' => '2026-10-06T12:01:00Z', 'status' => 'paid', 'paid' => true, 'verified_payment' => true,
            'charge_id' => 'ch_test', 'is_test' => true, 'billing_reason' => 'subscription_create',
            'regular_amount_minor' => 1200, 'discount_amount_minor' => 900,
        ];
        $this->gateway->event = ['id' => 'evt_test', 'type' => 'invoice.paid', 'object_id' => 'in_test', 'created' => '2026-10-06T12:01:00Z', 'is_test' => true];
    }

    public function test_paid_invoice_atomically_creates_fixed_period_payment_entitlement_and_event_once(): void
    {
        $this->settlement->settleInvoice('in_test');
        $this->travelTo(CarbonImmutable::parse('2026-10-09T12:00:00Z'));
        $this->settlement->settleInvoice('in_test');
        foreach (['billing_payments', 'billing_entitlements', 'billing_outbox'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $entitlement = DB::table('billing_entitlements')->first();
        $this->assertSame('2026-10-06T12:00:00Z', BillingStore::iso($entitlement->access_from));
        $this->assertSame('2026-10-13T12:00:00Z', BillingStore::iso($entitlement->access_until));
        $this->assertDatabaseHas('billing_orders', ['id' => $this->orderId, 'payment_state' => 'succeeded']);
        $this->assertNotNull(DB::table('billing_accounts')->first()->first_offer_used_at);
        $event = PaymentSettled::fromArray(BillingStore::json(DB::table('billing_outbox')->first()->payload));
        $this->assertSame('PaymentSettled.v1', $event->schema_version);
        $this->assertTrue($event->is_test);
        $this->assertSame('first_subscription', $event->transaction_kind->value);
    }

    public function test_mismatched_owner_amount_currency_mode_and_unverified_payment_never_grant_access(): void
    {
        $valid = $this->gateway->invoice;
        foreach ([['customer_id' => 'cus_other'], ['amount_minor' => 301], ['currency' => 'EUR'], ['is_test' => false],
            ['verified_payment' => false], ['regular_amount_minor' => 1500], ['period_end' => '2026-10-14T12:00:00Z'],
            ['billing_reason' => 'subscription_update']] as $changes) {
            $this->gateway->invoice = [...$valid, ...$changes];
            $this->reject(fn () => $this->settlement->settleInvoice('in_test'));
            $this->assertDatabaseCount('billing_payments', 0);
            $this->assertDatabaseCount('billing_entitlements', 0);
            $this->assertDatabaseCount('billing_outbox', 0);
        }
        $this->assertDatabaseHas('billing_orders', ['id' => $this->orderId, 'state' => 'review_required']);
    }

    public function test_unpaid_and_action_required_invoice_do_not_consume_discount_or_grant_access(): void
    {
        $this->gateway->invoice = [...$this->gateway->invoice, 'status' => 'open', 'paid' => false, 'verified_payment' => false, 'paid_at' => null, 'payment_state' => 'requires_action'];
        $this->settlement->settleInvoice('in_test');
        $this->assertDatabaseCount('billing_payments', 0);
        $this->assertNull(DB::table('billing_accounts')->first()->first_offer_used_at);
        $this->assertDatabaseHas('billing_orders', ['last_error' => 'PAYMENT_ACTION_REQUIRED']);
        $this->assertDatabaseHas('billing_subscriptions', ['resolution_required' => true, 'state' => 'pending']);
    }

    public function test_renewal_uses_original_price_and_its_actual_period_after_site_price_change(): void
    {
        $this->settlement->settleInvoice('in_test');
        DB::table('billing_prices')->insert(['id' => BillingStore::id(), 'version' => 2, 'regular_amount_minor' => 1500, 'first_week_amount_minor' => 500, 'currency' => 'USD', 'is_test' => true, 'created_at' => now()]);
        $this->renewal();
        $this->settlement->settleInvoice('in_renewal');
        $this->assertDatabaseCount('billing_payments', 2);
        $this->assertDatabaseHas('billing_payments', ['provider_invoice_id' => 'in_renewal', 'amount_minor' => 1200]);
        $order = DB::table('billing_orders')->where('transaction_kind', 'renewal')->first();
        $this->assertFalse($order->discount_applied);
        $this->assertSame('2026-10-20T12:00:00Z', BillingStore::iso(DB::table('billing_entitlements')->orderByDesc('access_until')->first()->access_until));
    }

    public function test_failed_renewal_keeps_old_paid_period_and_successful_retry_adds_only_invoice_period(): void
    {
        $this->settlement->settleInvoice('in_test');
        $this->renewal();
        $paid = $this->gateway->invoice;
        $this->gateway->subscription['status'] = 'past_due';
        $this->gateway->invoice = [...$paid, 'status' => 'open', 'paid' => false, 'verified_payment' => false, 'payment_state' => 'failed'];
        $this->settlement->settleInvoice('in_renewal');
        $this->assertDatabaseCount('billing_entitlements', 1);
        $this->assertDatabaseHas('billing_subscriptions', ['state' => 'past_due', 'resolution_required' => true]);
        $this->gateway->invoice = $paid;
        $this->gateway->subscription['status'] = 'active';
        $this->settlement->settleInvoice('in_renewal');
        $this->assertDatabaseCount('billing_entitlements', 2);
        $this->assertDatabaseHas('billing_subscriptions', ['state' => 'active', 'resolution_required' => false]);
    }

    public function test_distinct_invoice_for_same_paid_period_is_quarantined(): void
    {
        $this->settlement->settleInvoice('in_test');
        $this->gateway->invoice['id'] = 'in_other';
        $this->gateway->invoice['charge_id'] = 'ch_other';
        $this->reject(fn () => $this->settlement->settleInvoice('in_other'));
        $this->assertDatabaseCount('billing_payments', 1);
        $this->assertDatabaseCount('billing_entitlements', 1);
    }

    public function test_success_page_and_active_subscription_without_paid_invoice_do_not_create_entitlement(): void
    {
        $this->gateway->invoice = [...$this->gateway->invoice, 'status' => 'open', 'paid' => false, 'verified_payment' => false];
        $this->gateway->session = ['id' => 'cs_test', 'order_id' => $this->orderId, 'status' => 'complete', 'payment_status' => 'paid', 'subscription_id' => 'sub_test', 'customer_id' => 'cus_test'];
        $this->settlement->syncCheckout($this->orderId);
        $this->assertDatabaseCount('billing_entitlements', 0);
        $this->assertDatabaseHas('billing_subscriptions', ['resolution_required' => true]);
    }

    public function test_expired_checkout_releases_unpaid_subscription_and_discount_is_still_unused(): void
    {
        $this->gateway->session = ['id' => 'cs_test', 'order_id' => $this->orderId, 'status' => 'expired', 'payment_status' => 'unpaid', 'subscription_id' => null, 'customer_id' => null];
        $this->settlement->syncCheckout($this->orderId);
        $this->assertDatabaseHas('billing_subscriptions', ['state' => 'ended', 'resolution_required' => false]);
        $this->assertDatabaseHas('billing_orders', ['state' => 'expired']);
        $this->assertNull(DB::table('billing_accounts')->first()->first_offer_used_at);
    }

    public function test_pending_and_failed_refunds_do_not_revoke_paid_entitlement(): void
    {
        $this->settlement->settleInvoice('in_test');
        foreach (['pending', 'failed', 'canceled'] as $status) {
            $this->gateway->refundList = [['id' => 're_test', 'amount_minor' => 300, 'currency' => 'USD', 'status' => $status]];
            $this->settlement->syncRefunds('ch_test');
            $this->assertNull(DB::table('billing_entitlements')->first()->revoked_at);
            $this->assertSame(0, DB::table('billing_payments')->first()->refunded_amount_minor);
        }
    }

    public function test_partial_refund_records_review_without_revoking_access_or_resetting_discount(): void
    {
        $this->settlement->settleInvoice('in_test');
        $this->gateway->refundList = [['id' => 're_test', 'amount_minor' => 100, 'currency' => 'USD', 'status' => 'succeeded']];
        $this->settlement->syncRefunds('ch_test');
        $this->assertNull(DB::table('billing_entitlements')->first()->revoked_at);
        $this->assertDatabaseHas('billing_payments', ['refunded_amount_minor' => 100]);
        $this->assertDatabaseHas('billing_orders', ['state' => 'review_required', 'payment_state' => 'partially_refunded']);
        $this->settlement->syncSubscription('sub_test');
        $this->assertDatabaseHas('billing_subscriptions', ['resolution_required' => true]);
        $this->assertNotNull(DB::table('billing_accounts')->first()->first_offer_used_at);
    }

    public function test_full_refund_revokes_only_related_payment_and_stops_renewal(): void
    {
        $this->settlement->settleInvoice('in_test');
        $originalPayment = DB::table('billing_payments')->first();
        $this->renewal();
        $this->settlement->settleInvoice('in_renewal');
        $this->gateway->refundList = [['id' => 're_test', 'amount_minor' => 300, 'currency' => 'USD', 'status' => 'succeeded']];
        $this->settlement->syncRefunds('ch_test');
        $this->assertNotNull(DB::table('billing_entitlements')->where('payment_id', $originalPayment->id)->first()->revoked_at);
        $this->assertSame(1, DB::table('billing_entitlements')->whereNull('revoked_at')->count());
        $this->assertDatabaseHas('billing_subscriptions', ['refund_stopped' => true, 'renewal_enabled' => false]);
        $this->assertNotNull(DB::table('billing_accounts')->first()->first_offer_used_at);
    }

    public function test_webhook_is_durable_deduplicated_and_uses_current_fact_even_for_late_failure_event(): void
    {
        $webhook = app(WebhookService::class);
        $this->gateway->event['type'] = 'invoice.payment_failed';
        $webhook->receive('not stored', 'not stored');
        $webhook->receive('not stored', 'not stored');
        $this->assertDatabaseCount('billing_inbox', 1);
        $this->assertDatabaseCount('billing_payments', 0);
        $this->assertSame(1, $webhook->processPending());
        $this->assertDatabaseHas('billing_orders', ['payment_state' => 'succeeded']);
        $this->assertDatabaseHas('billing_inbox', ['state' => 'processed']);
    }

    public function test_invalid_signature_never_reaches_inbox_and_provider_outage_can_be_retried(): void
    {
        $webhook = app(WebhookService::class);
        $this->gateway->badSignature = true;
        $this->reject(fn () => $webhook->receive('raw payment payload', 'bad signature'));
        $this->assertDatabaseCount('billing_inbox', 0);
        $this->gateway->badSignature = false;
        $webhook->receive('not stored', 'not stored');
        $this->gateway->unavailable = true;
        $this->assertSame(0, $webhook->processPending());
        $this->assertDatabaseHas('billing_inbox', ['state' => 'pending', 'attempts' => 1, 'last_error' => 'RECONCILIATION_REQUIRED']);
        $this->gateway->unavailable = false;
        $this->assertSame(1, $webhook->processPending());
        $this->assertDatabaseCount('billing_payments', 1);
    }

    public function test_outbox_keeps_missing_or_failing_consumer_pending_without_rolling_back_payment(): void
    {
        $this->settlement->settleInvoice('in_test');
        $outbox = app(OutboxService::class);
        $this->assertSame(0, $outbox->dispatchPending());
        $this->assertDatabaseHas('billing_outbox', ['delivered_at' => null, 'last_error' => 'CONSUMER_NOT_READY']);
        app('events')->listen(PaymentSettled::class, fn () => throw new RuntimeException('Advertising unavailable'));
        $this->assertSame(0, $outbox->dispatchPending());
        $this->assertDatabaseHas('billing_outbox', ['delivered_at' => null, 'last_error' => 'CONSUMER_RETRY_REQUIRED']);
        $this->assertDatabaseCount('billing_payments', 1);
        $this->assertDatabaseCount('billing_entitlements', 1);
        app('events')->forget(PaymentSettled::class);
        $ids = [];
        app('events')->listen(PaymentSettled::class, function (PaymentSettled $event) use (&$ids) {
            $ids[] = $event->event_id;
        });
        $this->assertSame(1, $outbox->dispatchPending());
        $this->assertSame(0, $outbox->dispatchPending());
        $this->assertSame([DB::table('billing_outbox')->first()->id], $ids);
    }

    public function test_canceled_subscription_with_unresolved_initial_invoice_cannot_release_purchase_slot(): void
    {
        $this->gateway->subscription['status'] = 'canceled';
        $this->gateway->invoiceUnavailable = true;
        $this->reject(fn () => $this->settlement->syncSubscription('sub_test'));
        $this->assertDatabaseHas('billing_subscriptions', ['state' => 'pending', 'resolution_required' => true]);
        $this->assertDatabaseCount('billing_entitlements', 0);
        $this->gateway->invoiceUnavailable = false;
        $this->settlement->syncSubscription('sub_test');
        $this->assertDatabaseHas('billing_subscriptions', ['state' => 'canceling', 'resolution_required' => false]);
        $this->assertDatabaseCount('billing_entitlements', 1);
    }

    public function test_payment_and_entitlement_rollback_when_outbox_cannot_be_saved(): void
    {
        DB::statement('ALTER TABLE billing_outbox ADD CONSTRAINT test_reject_event CHECK (false)');
        $this->reject(fn () => $this->settlement->settleInvoice('in_test'));
        $this->assertDatabaseCount('billing_payments', 0);
        $this->assertDatabaseCount('billing_entitlements', 0);
        $this->assertDatabaseCount('billing_outbox', 0);
        $this->assertNull(DB::table('billing_accounts')->first()->first_offer_used_at);
    }

    public function test_failed_inbox_item_does_not_starve_later_items_when_batch_is_one(): void
    {
        $webhook = app(WebhookService::class);
        $webhook->receive('not stored', 'not stored');
        $this->travel(1)->seconds();
        $this->gateway->event = [...$this->gateway->event, 'id' => 'evt_later', 'type' => 'customer.created'];
        $webhook->receive('not stored', 'not stored');
        $this->gateway->unavailable = true;
        $this->assertSame(0, $webhook->processPending(1));
        $this->assertSame(1, $webhook->processPending(1));
        $this->assertDatabaseHas('billing_inbox', ['provider_event_id' => 'evt_test', 'state' => 'pending']);
        $this->assertDatabaseHas('billing_inbox', ['provider_event_id' => 'evt_later', 'state' => 'ignored']);
    }

    public function test_failed_outbox_item_does_not_starve_later_payments(): void
    {
        $this->settlement->settleInvoice('in_test');
        $first = DB::table('billing_outbox')->first()->id;
        $this->travel(1)->seconds();
        $this->renewal();
        $this->settlement->settleInvoice('in_renewal');
        app('events')->listen(PaymentSettled::class, function (PaymentSettled $event) use ($first) {
            if ($event->event_id === $first) {
                throw new RuntimeException('Temporary failure');
            }
        });
        $outbox = app(OutboxService::class);
        $this->assertSame(0, $outbox->dispatchPending(1));
        $this->assertSame(1, $outbox->dispatchPending(1));
        $this->assertDatabaseHas('billing_outbox', ['id' => $first, 'delivered_at' => null]);
    }

    public function test_full_refund_cancel_timeout_is_retryable_and_does_not_undo_refund_fact(): void
    {
        $this->settlement->settleInvoice('in_test');
        $this->gateway->refundList = [['id' => 're_test', 'amount_minor' => 300, 'currency' => 'USD', 'status' => 'succeeded']];
        $this->gateway->failRenewal = true;
        $this->settlement->syncRefunds('ch_test');
        $this->assertNotNull(DB::table('billing_entitlements')->first()->revoked_at);
        $this->assertDatabaseHas('billing_subscriptions', ['refund_stopped' => true, 'renewal_enabled' => true, 'resolution_required' => true]);
        $this->assertDatabaseHas('billing_operations', ['kind' => 'cancel', 'state' => 'pending']);
        $this->gateway->failRenewal = false;
        $this->settlement->syncRefunds('ch_test');
        $this->assertDatabaseHas('billing_subscriptions', ['refund_stopped' => true, 'renewal_enabled' => false, 'resolution_required' => false]);
        $version = DB::table('billing_subscriptions')->first()->confirmation_version;
        $this->settlement->syncRefunds('ch_test');
        $this->assertSame($version, DB::table('billing_subscriptions')->first()->confirmation_version);
        $this->assertDatabaseHas('billing_subscriptions', ['resolution_required' => false]);
    }

    public function test_invoice_sync_does_not_replace_version_of_pending_cancel_operation(): void
    {
        DB::table('billing_operations')->insert([
            'id' => BillingStore::id(), 'viewer_id' => $this->viewerId, 'subscription_id' => $this->subscriptionId,
            'kind' => 'cancel', 'expected_version' => 1, 'state' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->settlement->settleInvoice('in_test');
        $this->assertDatabaseHas('billing_subscriptions', ['confirmation_version' => 1, 'resolution_required' => true]);
        $this->assertDatabaseHas('billing_operations', ['kind' => 'cancel', 'expected_version' => 1, 'state' => 'pending']);
        $this->assertDatabaseCount('billing_payments', 1);
    }

    private function renewal(): void
    {
        $this->gateway->subscription = [...$this->gateway->subscription, 'period_start' => '2026-10-13T12:00:00Z', 'period_end' => '2026-10-20T12:00:00Z', 'latest_invoice_id' => 'in_renewal'];
        $this->gateway->invoice = [...$this->gateway->invoice, 'id' => 'in_renewal', 'charge_id' => 'ch_renewal', 'amount_minor' => 1200,
            'period_start' => '2026-10-13T12:00:00Z', 'period_end' => '2026-10-20T12:00:00Z', 'paid_at' => '2026-10-13T12:01:00Z',
            'billing_reason' => 'subscription_cycle', 'discount_amount_minor' => 0];
    }

    private function reject(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unverified facts must be rejected.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
    }
}
