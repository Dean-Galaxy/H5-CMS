<?php

namespace Tests\Feature\Billing;

use App\Contracts\MembershipReader;
use App\Data\PaymentSettled;
use App\Modules\Billing\Data\ExternalPayment;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\PaymentLedger;
use App\Modules\Billing\Services\SettlementService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Billing\Support\FakeGateway;
use Tests\Feature\Billing\Support\ProviderLedgerFixture;
use Tests\TestCase;

final class ProviderPaymentLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_providers_use_one_paid_ledger_entitlement_and_event_without_colliding_ids(): void
    {
        foreach ([ProviderScope::legacyStripe(), new ProviderScope('paypal', 'sandbox', 'merchant-a'), new ProviderScope('paypal', 'sandbox', 'merchant-b')] as $scope) {
            [$viewer, $order, $sub, $fact] = ProviderLedgerFixture::create($scope);
            $paymentId = app(PaymentLedger::class)->record($sub->id, $fact);
            self::assertSame($paymentId, app(PaymentLedger::class)->record($sub->id, $fact));
            self::assertTrue(app(MembershipReader::class)->membership($viewer->id, now()->toDateTimeImmutable())->access_active);
            $event = PaymentSettled::fromArray(BillingStore::json(DB::table('billing_outbox')->where('payment_id', $paymentId)->first()->payload));
            self::assertSame($order->id, $event->order_id);
            self::assertSame('first_subscription', $event->transaction_kind->value);
            self::assertTrue($event->is_test);
            self::assertSame(300, $event->amount_minor);
        }
        self::assertDatabaseCount('billing_payments', 3);
        self::assertDatabaseCount('billing_entitlements', 3);
        self::assertDatabaseCount('billing_outbox', 3);
    }

    public function test_late_payment_uses_its_original_period_and_replay_does_not_restore_refunded_entitlement(): void
    {
        [$viewer, , $sub, $fact] = ProviderLedgerFixture::create(new ProviderScope('paypal', 'sandbox', 'merchant-a'));
        $this->travel(10)->days();
        $id = app(PaymentLedger::class)->record($sub->id, $fact);
        self::assertFalse(app(MembershipReader::class)->membership($viewer->id, now()->toDateTimeImmutable())->access_active);
        $row = DB::table('billing_entitlements')->where('payment_id', $id)->first();
        self::assertSame(BillingStore::iso($fact->periodEnd), BillingStore::iso($row->access_until));
        DB::table('billing_entitlements')->where('payment_id', $id)->update(['revoked_at' => now()]);
        app(PaymentLedger::class)->record($sub->id, $fact);
        self::assertNotNull(DB::table('billing_entitlements')->where('payment_id', $id)->first()->revoked_at);
        self::assertNotNull(DB::table('billing_accounts')->where('viewer_id', $viewer->id)->first()->first_offer_used_at);
    }

    public function test_renewal_has_original_price_and_does_not_turn_unpaid_gap_into_access(): void
    {
        [$viewer, , $sub, $fact] = ProviderLedgerFixture::create(new ProviderScope('paypal', 'sandbox', 'merchant-a'));
        app(PaymentLedger::class)->record($sub->id, $fact);
        $start = $fact->periodEnd->modify('+7 days');
        $next = new ExternalPayment($fact->scope, 'later-payment', 'later-charge', $fact->externalSubscriptionId, $fact->customerId,
            $fact->firstOrderId, false, 1200, 1200, 'USD', $start, $start->modify('+7 days'), $start->modify('+1 day'));
        app(PaymentLedger::class)->record($sub->id, $next);
        $gap = $fact->periodEnd->modify('+1 day');
        self::assertFalse(app(MembershipReader::class)->membership($viewer->id, $gap)->access_active);
        self::assertTrue(app(MembershipReader::class)->membership($viewer->id, $start->modify('+1 day'))->access_active);
        self::assertDatabaseHas('billing_orders', ['subscription_id' => $sub->id, 'transaction_kind' => 'renewal', 'amount_minor' => 1200, 'discount_applied' => false, 'provider' => 'paypal']);
    }

    public function test_mismatched_scope_amount_customer_or_first_order_cannot_write_any_paid_fact(): void
    {
        [, , $sub, $original] = ProviderLedgerFixture::create(new ProviderScope('paypal', 'sandbox', 'merchant-a'));
        foreach (['scope', 'amount', 'customer', 'order'] as $field) {
            $fact = new ExternalPayment($field === 'scope' ? new ProviderScope('paypal', 'sandbox', 'merchant-b') : $original->scope,
                $original->reference, $original->chargeId, $original->externalSubscriptionId,
                $field === 'customer' ? 'wrong-customer' : $original->customerId,
                $field === 'order' ? BillingStore::id() : $original->firstOrderId, true,
                $field === 'amount' ? 301 : 300, 1200, 'USD', $original->periodStart, $original->periodEnd, $original->paidAt);
            try {
                app(PaymentLedger::class)->record($sub->id, $fact);
                self::fail('Expected fact mismatch');
            } catch (RuntimeException $e) {
                self::assertSame('BILLING_FACT_MISMATCH', $e->getMessage());
            }
        }
        self::assertDatabaseCount('billing_payments', 0);
        self::assertDatabaseCount('billing_entitlements', 0);
        self::assertDatabaseCount('billing_outbox', 0);
    }

    public function test_second_transaction_cannot_overlap_an_already_paid_cycle(): void
    {
        [, , $sub, $fact] = ProviderLedgerFixture::create(new ProviderScope('paypal', 'sandbox', 'merchant-a'));
        app(PaymentLedger::class)->record($sub->id, $fact);
        $different = new ExternalPayment($fact->scope, 'different-payment', 'different-charge', $fact->externalSubscriptionId,
            $fact->customerId, $fact->firstOrderId, false, 1200, 1200, 'USD', $fact->periodStart, $fact->periodEnd, $fact->paidAt);
        try {
            app(PaymentLedger::class)->record($sub->id, $different);
            self::fail('Expected overlap rejection');
        } catch (RuntimeException $e) {
            self::assertSame('BILLING_FACT_MISMATCH', $e->getMessage());
            self::assertDatabaseCount('billing_payments', 1);
            self::assertDatabaseCount('billing_orders', 1);
        }
    }

    public function test_successful_first_offer_is_not_available_again_after_switching_provider(): void
    {
        [$viewer, , $stripeSub, $stripeFact] = ProviderLedgerFixture::create(ProviderScope::legacyStripe());
        app(PaymentLedger::class)->record($stripeSub->id, $stripeFact);
        DB::table('billing_subscriptions')->where('id', $stripeSub->id)->update(['state' => 'ended']);
        [, $paypalOrder, $paypalSub, $paypalFact] = ProviderLedgerFixture::create(new ProviderScope('paypal', 'sandbox', 'merchant-a'));
        DB::table('billing_subscriptions')->where('id', $paypalSub->id)->update(['viewer_id' => $viewer->id]);
        DB::table('billing_orders')->where('id', $paypalOrder->id)->update(['viewer_id' => $viewer->id]);
        try {
            app(PaymentLedger::class)->record($paypalSub->id, $paypalFact);
            self::fail('Expected cross-provider discount rejection');
        } catch (RuntimeException $e) {
            self::assertSame('BILLING_FACT_MISMATCH', $e->getMessage());
            self::assertDatabaseCount('billing_payments', 1);
            self::assertDatabaseCount('billing_outbox', 1);
        }
    }

    public function test_stripe_refund_with_colliding_ids_cannot_revoke_paypal_payment(): void
    {
        [, , $stripeSub, $stripeFact] = ProviderLedgerFixture::create(ProviderScope::legacyStripe());
        $paypalScope = new ProviderScope('paypal', 'sandbox', 'merchant-a');
        [, , $paypalSub, $paypalFact] = ProviderLedgerFixture::create($paypalScope);
        $stripePayment = app(PaymentLedger::class)->record($stripeSub->id, $stripeFact);
        $paypalPayment = app(PaymentLedger::class)->record($paypalSub->id, $paypalFact);
        DB::table('billing_subscriptions')->where('id', $stripeSub->id)->update(['state' => 'ended']);
        DB::table('billing_refunds')->insert($paypalScope->columns() + [
            'provider_refund_id' => 'same-refund', 'payment_id' => $paypalPayment, 'amount_minor' => 100, 'status' => 'succeeded', 'created_at' => now(),
        ]);
        DB::table('billing_payments')->where('id', $paypalPayment)->update(['refunded_amount_minor' => 100]);
        $gateway = new FakeGateway;
        $gateway->refunds['same-charge'] = [['id' => 'same-refund', 'amount_minor' => 300, 'currency' => 'USD', 'status' => 'succeeded']];
        (new SettlementService($gateway))->syncRefunds('same-charge');
        self::assertNotNull(DB::table('billing_entitlements')->where('payment_id', $stripePayment)->first()->revoked_at);
        self::assertNull(DB::table('billing_entitlements')->where('payment_id', $paypalPayment)->first()->revoked_at);
        self::assertSame(100, DB::table('billing_payments')->find($paypalPayment)->refunded_amount_minor);
        self::assertFalse(DB::table('billing_subscriptions')->find($paypalSub->id)->refund_stopped);
        self::assertDatabaseCount('billing_refunds', 2);
    }

    public function test_outbox_insert_failure_rolls_back_payment_access_order_and_discount_together(): void
    {
        [$viewer, $order, $sub, $fact] = ProviderLedgerFixture::create(new ProviderScope('paypal', 'sandbox', 'merchant-a'));
        DB::statement('ALTER TABLE billing_outbox ADD CONSTRAINT local_test_reject_outbox CHECK (false)');
        try {
            app(PaymentLedger::class)->record($sub->id, $fact);
            self::fail('Expected local outbox storage failure');
        } catch (QueryException) {
            self::assertDatabaseCount('billing_payments', 0);
            self::assertDatabaseCount('billing_entitlements', 0);
            self::assertDatabaseCount('billing_outbox', 0);
            self::assertSame('processing', DB::table('billing_orders')->find($order->id)->state);
            self::assertNull(DB::table('billing_accounts')->where('viewer_id', $viewer->id)->first()->first_offer_used_at);
        } finally {
            DB::statement('ALTER TABLE billing_outbox DROP CONSTRAINT local_test_reject_outbox');
        }
    }
}
