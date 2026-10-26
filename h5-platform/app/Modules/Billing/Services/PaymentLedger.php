<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Data\ExternalPayment;
use App\Modules\Billing\Gateway\ProviderScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** The only successful-payment writer for both providers; no HTTP or provider status inference. */
final class PaymentLedger
{
    public function record(string $subscriptionId, ExternalPayment $fact): string
    {
        return DB::transaction(function () use ($subscriptionId, $fact) {
            $sub = DB::table('billing_subscriptions')->find($subscriptionId);
            $this->requireFact($sub !== null && $fact->scope->matches($sub));
            $account = BillingStore::lock($sub->viewer_id);
            $sub = DB::table('billing_subscriptions')->find($subscriptionId);
            $firstOrder = DB::table('billing_orders')->find($sub->first_order_id);
            $this->requireFact($fact->scope->matches($sub) && $firstOrder !== null && $fact->scope->matches($firstOrder)
                && $firstOrder->viewer_id === $sub->viewer_id && $firstOrder->subscription_id === $sub->id
                && $firstOrder->regular_amount_minor === $sub->regular_amount_minor && $firstOrder->price_version === $sub->price_version
                && $firstOrder->currency === $sub->currency
                && $fact->firstOrderId === $firstOrder->id && ($sub->provider_id === null || $fact->externalSubscriptionId === $sub->provider_id)
                && ($sub->customer_id === null || $fact->customerId === $sub->customer_id) && $fact->regularAmountMinor === $sub->regular_amount_minor
                && $fact->currency === $sub->currency && (bool) $sub->is_test && (bool) $firstOrder->is_test);
            if ($sub->provider_id === null || $sub->customer_id === null) {
                DB::table('billing_subscriptions')->where('id', $sub->id)->update([
                    'provider_id' => $fact->externalSubscriptionId, 'customer_id' => $fact->customerId, 'updated_at' => now(),
                ]);
            }
            $expected = $fact->firstCycle ? $firstOrder->amount_minor : $sub->regular_amount_minor;
            $this->requireFact($fact->amountMinor === $expected);
            $order = $fact->firstCycle ? $firstOrder : $this->renewalOrder($sub, $firstOrder, $fact->reference);
            $existing = DB::table('billing_payments')->where($fact->scope->columns())->where('provider_invoice_id', $fact->reference)->first();
            if ($existing) {
                $this->requireFact($existing->order_id === $order->id && $existing->subscription_id === $sub->id
                    && $existing->viewer_id === $sub->viewer_id && $existing->provider_charge_id === $fact->chargeId
                    && $existing->amount_minor === $expected && $existing->currency === $fact->currency
                    && BillingStore::iso($existing->period_start) === BillingStore::iso($fact->periodStart)
                    && BillingStore::iso($existing->period_end) === BillingStore::iso($fact->periodEnd));

                return $existing->id;
            }
            $this->requireFact(! DB::table('billing_payments')->where('subscription_id', $sub->id)
                ->where('period_start', '<', $fact->periodEnd)->where('period_end', '>', $fact->periodStart)->exists());
            $this->requireFact(! DB::table('billing_payments')->where('order_id', $order->id)->exists());
            if ($fact->firstCycle && $order->discount_applied) {
                $this->requireFact($account->first_offer_used_at === null);
            }
            $paymentId = BillingStore::id();
            DB::table('billing_payments')->insert($fact->scope->columns() + [
                'id' => $paymentId, 'order_id' => $order->id, 'subscription_id' => $sub->id, 'viewer_id' => $sub->viewer_id,
                // Historical column name retained for compatibility: PayPal uses a Sale reference, not an invoice.
                'provider_invoice_id' => $fact->reference, 'provider_charge_id' => $fact->chargeId,
                'amount_minor' => $expected, 'currency' => $fact->currency, 'is_test' => true,
                'period_start' => $fact->periodStart, 'period_end' => $fact->periodEnd, 'paid_at' => $fact->paidAt, 'created_at' => now(),
            ]);
            DB::table('billing_entitlements')->insert([
                'id' => BillingStore::id(), 'payment_id' => $paymentId, 'viewer_id' => $sub->viewer_id,
                'subscription_id' => $sub->id, 'access_from' => $fact->periodStart, 'access_until' => $fact->periodEnd,
            ]);
            DB::table('billing_orders')->where('id', $order->id)->update([
                'state' => 'paid', 'payment_state' => 'succeeded', 'last_error' => null, 'updated_at' => now(),
            ]);
            $changes = [];
            if ($account->first_paid_at === null || CarbonImmutable::parse($account->first_paid_at)->gt($fact->paidAt)) {
                $changes['first_paid_at'] = $fact->paidAt;
            }
            if ($fact->firstCycle && $order->discount_applied) {
                $changes['first_offer_used_at'] = $fact->paidAt;
            }
            if ($changes) {
                DB::table('billing_accounts')->where('viewer_id', $sub->viewer_id)->update($changes);
            }
            $eventId = BillingStore::id();
            DB::table('billing_outbox')->insert([
                'id' => $eventId, 'payment_id' => $paymentId,
                'payload' => json_encode([
                    'event_id' => $eventId, 'schema_version' => 'PaymentSettled.v1', 'payment_id' => $paymentId,
                    'order_id' => $order->id, 'subscription_id' => $sub->id, 'transaction_kind' => $order->transaction_kind,
                    'discount_applied' => (bool) $order->discount_applied, 'amount_minor' => $expected,
                    'currency' => $fact->currency, 'occurred_at' => BillingStore::iso($fact->paidAt), 'is_test' => true,
                    'attribution_snapshot' => BillingStore::json($order->attribution_snapshot),
                ], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);

            return $paymentId;
        }, attempts: 3);
    }

    /** Also used by the existing unpaid Stripe invoice flow; does not grant any access. */
    public function renewalOrder(object $sub, object $firstOrder, string $reference): object
    {
        $scope = ProviderScope::fromRecord($sub);
        // Preserve the old Stripe key exactly. Other providers cannot alias that key.
        $key = $scope->provider === 'stripe' && $scope->merchantScope === 'legacy-stripe-test'
            ? 'invoice:'.$reference : 'payment:'.hash('sha256', json_encode([$scope->columns(), $reference], JSON_THROW_ON_ERROR));
        $existing = DB::table('billing_orders')->where('viewer_id', $sub->viewer_id)->where('idempotency_key', $key)->first();
        if ($existing) {
            $this->requireFact($scope->matches($existing) && $existing->subscription_id === $sub->id && $existing->transaction_kind === 'renewal'
                && hash_equals(hash('sha256', $key), $existing->fingerprint) && $existing->amount_minor === $sub->regular_amount_minor
                && ! $existing->discount_applied && $existing->currency === $sub->currency && (bool) $existing->is_test === (bool) $sub->is_test);

            return $existing;
        }
        $id = BillingStore::id();
        DB::table('billing_orders')->insert($scope->columns() + [
            'id' => $id, 'viewer_id' => $sub->viewer_id, 'subscription_id' => $sub->id,
            'idempotency_key' => $key, 'fingerprint' => hash('sha256', $key), 'transaction_kind' => 'renewal',
            'price_version' => $sub->price_version, 'amount_minor' => $sub->regular_amount_minor,
            'regular_amount_minor' => $sub->regular_amount_minor, 'discount_applied' => false,
            'currency' => $sub->currency, 'is_test' => $sub->is_test, 'state' => 'processing', 'payment_state' => 'pending',
            'return_to' => '/', 'attribution_snapshot' => $firstOrder->attribution_snapshot, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('billing_orders')->find($id);
    }

    private function requireFact(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('BILLING_FACT_MISMATCH');
        }
    }
}
