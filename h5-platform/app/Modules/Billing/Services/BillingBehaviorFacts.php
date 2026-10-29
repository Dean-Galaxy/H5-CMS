<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use Illuminate\Support\Facades\DB;

/** Local durable facts only. Called inside the authoritative financial transaction, never a recorder/network call. */
final class BillingBehaviorFacts
{
    public function refund(string $paymentId): void
    {
        $payment = DB::table('billing_payments')->find($paymentId);
        if (! $payment) {
            return;
        }
        DB::transaction(function () use ($payment) {
            BillingStore::lock($payment->viewer_id);
            $payment = DB::table('billing_payments')->find($payment->id);
            $order = DB::table('billing_orders')->find($payment->order_id);
            $properties = $this->properties($payment, $order) + ['payment_id' => $payment->id,
                'amount_minor' => $payment->amount_minor, 'refunded_amount_minor' => $payment->refunded_amount_minor,
                'currency' => $payment->currency, 'transaction_kind' => $order->transaction_kind];
            $this->observe('refund:'.$payment->id, (string) $payment->refunded_amount_minor, 'refund_confirmed', $properties, $payment->refunded_amount_minor > 0);
        });
    }

    public function subscription(string $subscriptionId, bool $confirmedAction = false): void
    {
        $sub = DB::table('billing_subscriptions')->find($subscriptionId);
        if (! $sub) {
            return;
        }
        DB::transaction(function () use ($sub, $confirmedAction) {
            BillingStore::lock($sub->viewer_id);
            $sub = DB::table('billing_subscriptions')->find($sub->id);
            // A pending/failed request is not a confirmation, nor is initial checkout activation a resume.
            $enabled = (bool) $sub->renewal_enabled;
            $confirmed = $enabled
                ? in_array($sub->provider_status, ['active', 'ACTIVE'], true)
                : in_array($sub->provider_status, ['active', 'canceled', 'incomplete_expired', 'SUSPENDED', 'CANCELLED', 'EXPIRED'], true);
            if (! $confirmed || ! $sub->provider_id || DB::table('billing_operations')->where('subscription_id', $sub->id)->where('state', 'pending')->exists()) {
                return;
            }
            $order = DB::table('billing_orders')->find($sub->first_order_id);
            $this->observe('subscription:'.$sub->id, $enabled ? 'enabled' : 'disabled', $enabled ? 'subscription_resumed' : 'subscription_cancelled',
                $this->properties($sub, $order), ! $enabled && $confirmedAction);
        });
    }

    private function properties(object $fact, object $order): array
    {
        $snapshot = FinancialAnalyticsReader::snapshot($order->attribution_snapshot);
        $properties = ['viewer_id' => $fact->viewer_id, 'order_id' => $order->id,
            'subscription_id' => $fact->subscription_id ?? $fact->id, 'is_test' => (bool) $fact->is_test];
        if ($snapshot !== null) {
            $properties['source_id'] = $snapshot['source_id'];
        }

        return $properties;
    }

    private function observe(string $aggregate, string $value, string $event, array $properties, bool $emitInitial): void
    {
        $previous = DB::table('billing_analytics_states')->where('aggregate_key', $aggregate)->first();
        if ($previous && $previous->value === $value) {
            return;
        }
        $version = $previous ? $previous->fact_version + 1 : ($emitInitial ? 1 : 0);
        DB::table('billing_analytics_states')->updateOrInsert(['aggregate_key' => $aggregate],
            ['fact_version' => $version, 'value' => $value, 'observed_at' => now()]);
        if (! $previous && ! $emitInitial) {
            return;
        }
        $observedAt = now()->utc()->startOfSecond();
        $properties['occurred_at'] = BillingStore::iso($observedAt);
        if ($event === 'refund_confirmed') {
            $properties['fact_version'] = $version;
        }
        DB::table('billing_behavior_outbox')->insert(['id' => BillingStore::id(), 'aggregate_key' => $aggregate,
            'fact_version' => $version, 'event_name' => $event, 'properties' => json_encode($properties, JSON_THROW_ON_ERROR), 'occurred_at' => $observedAt]);
    }
}
