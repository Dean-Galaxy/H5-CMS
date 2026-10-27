<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Data\ExternalPayment;
use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Gateway\ProviderScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Stripe notifications are hints; only freshly retrieved, matched facts enter the ledger. */
final class SettlementService
{
    public function __construct(private PaymentGateway $gateway) {}

    public function settleInvoice(string $invoiceId): void
    {
        $hint = $this->gateway->invoice($invoiceId);
        $firstOrder = DB::table('billing_orders')->where('id', $hint['order_id'] ?? '')->first();
        if (! $firstOrder || ! ProviderScope::legacyStripe()->matches($firstOrder)) {
            throw new RuntimeException('BILLING_ORDER_NOT_FOUND');
        }
        $this->guarded($firstOrder->subscription_id, function () use ($invoiceId, $firstOrder) {
            $account = BillingStore::lock($firstOrder->viewer_id);
            $invoice = $this->gateway->invoice($invoiceId);
            $sub = DB::table('billing_subscriptions')->where('id', $firstOrder->subscription_id)->first();
            $provider = $this->gateway->subscription($invoice['subscription_id']);
            $this->matchSubscription($sub, $provider);
            $this->requireFact(($invoice['id'] ?? null) === $invoiceId
                && ($invoice['order_id'] ?? null) === $sub->first_order_id
                && ($invoice['subscription_id'] ?? null) === $provider['id']
                && ($invoice['customer_id'] ?? null) === $provider['customer_id']
                && ($invoice['currency'] ?? null) === $sub->currency
                && ($invoice['is_test'] ?? null) === (bool) $sub->is_test
                && ($invoice['regular_amount_minor'] ?? null) === $sub->regular_amount_minor);
            $reason = $invoice['billing_reason'] ?? '';
            $this->requireFact(in_array($reason, ['subscription_create', 'subscription_cycle'], true));
            $initial = $reason === 'subscription_create';
            $expectedAmount = $initial ? $firstOrder->amount_minor : $sub->regular_amount_minor;
            $expectedDiscount = $initial ? $sub->regular_amount_minor - $firstOrder->amount_minor : 0;
            $this->requireFact(($invoice['amount_minor'] ?? null) === $expectedAmount
                && ($invoice['discount_amount_minor'] ?? null) === $expectedDiscount);
            $start = CarbonImmutable::parse($invoice['period_start'])->utc();
            $end = CarbonImmutable::parse($invoice['period_end'])->utc();
            $this->requireFact($end->timestamp - $start->timestamp === 7 * 86400);
            $order = $initial ? $firstOrder : app(PaymentLedger::class)->renewalOrder($sub, $firstOrder, $invoiceId);
            $payment = DB::table('billing_payments')->where(ProviderScope::legacyStripe()->columns())->where('provider_invoice_id', $invoiceId)->first();
            if ($payment) {
                $this->requireFact($payment->order_id === $order->id && $payment->amount_minor === $expectedAmount
                    && BillingStore::iso($payment->period_start) === BillingStore::iso($start)
                    && BillingStore::iso($payment->period_end) === BillingStore::iso($end));
                $this->applySubscription($sub, $provider);

                return;
            }
            if (! ($invoice['paid'] ?? false) || ($invoice['status'] ?? null) !== 'paid') {
                $failed = in_array($invoice['payment_state'] ?? '', ['failed', 'requires_action'], true)
                    || in_array($invoice['status'] ?? '', ['void', 'uncollectible'], true);
                DB::table('billing_orders')->where('id', $order->id)->update([
                    'state' => $failed ? 'failed' : 'processing', 'payment_state' => $failed ? 'failed' : 'pending',
                    'last_error' => ($invoice['payment_state'] ?? '') === 'requires_action' ? 'PAYMENT_ACTION_REQUIRED' : ($failed ? 'PAYMENT_FAILED' : null),
                    'updated_at' => now(),
                ]);
                $this->applySubscription($sub, $provider,
                    ($provider['latest_invoice_id'] ?? null) === $invoiceId && ($invoice['status'] ?? null) === 'void');

                return;
            }
            $this->requireFact(($invoice['verified_payment'] ?? false) === true && ! empty($invoice['paid_at']) && ! empty($invoice['charge_id']));
            app(PaymentLedger::class)->record($sub->id, new ExternalPayment(
                ProviderScope::fromRecord($sub), $invoiceId, $invoice['charge_id'], $provider['id'], $provider['customer_id'],
                $firstOrder->id, $initial, $expectedAmount, $sub->regular_amount_minor, $sub->currency,
                $start, $end, CarbonImmutable::parse($invoice['paid_at'])->utc(),
            ));
            $this->applySubscription($sub, $provider);
        });
    }

    public function syncSubscription(string $providerId): void
    {
        $hint = $this->gateway->subscription($providerId);
        $sub = DB::table('billing_subscriptions')->where(ProviderScope::legacyStripe()->columns())->where('provider_id', $providerId)->first();
        if (! $sub && ! empty($hint['order_id'])) {
            $sub = DB::table('billing_subscriptions')->where(ProviderScope::legacyStripe()->columns())->where('first_order_id', $hint['order_id'])->first();
        }
        if (! $sub) {
            throw new RuntimeException('BILLING_SUBSCRIPTION_NOT_FOUND');
        }
        $latestInvoice = $this->guarded($sub->id, function () use ($sub, $providerId) {
            BillingStore::lock($sub->viewer_id);
            $current = DB::table('billing_subscriptions')->where('id', $sub->id)->first();
            $provider = $this->gateway->subscription($providerId);
            $this->matchSubscription($current, $provider);
            $this->applySubscription($current, $provider);

            return $provider['latest_invoice_id'] ?? null;
        });
        if ($latestInvoice) {
            $this->settleInvoice($latestInvoice);
        }
    }

    public function syncCheckout(string $orderId): void
    {
        $order = DB::table('billing_orders')->where('id', $orderId)->first();
        if (! $order || ! ProviderScope::legacyStripe()->matches($order) || ! $order->checkout_id) {
            throw new RuntimeException('BILLING_CHECKOUT_NOT_READY');
        }
        $providerId = $this->guarded($order->subscription_id, function () use ($order) {
            BillingStore::lock($order->viewer_id);
            $order = DB::table('billing_orders')->where('id', $order->id)->first();
            $session = $this->gateway->checkout($order->checkout_id);
            $this->requireFact(($session['id'] ?? null) === $order->checkout_id && ($session['order_id'] ?? null) === $order->id);
            if (! empty($session['subscription_id'])) {
                $provider = $this->gateway->subscription($session['subscription_id']);
                $sub = DB::table('billing_subscriptions')->where('id', $order->subscription_id)->first();
                $this->matchSubscription($sub, $provider);
                $this->requireFact(($session['customer_id'] ?? null) === $provider['customer_id']);
                $this->applySubscription($sub, $provider);

                return $provider['id'];
            }
            if (($session['status'] ?? null) === 'expired') {
                $this->requireFact(! DB::table('billing_payments')->where('subscription_id', $order->subscription_id)->exists());
                DB::table('billing_orders')->where('id', $order->id)->update(['state' => 'expired', 'payment_state' => 'failed', 'updated_at' => now()]);
                DB::table('billing_subscriptions')->where('id', $order->subscription_id)->update([
                    'state' => 'ended', 'renewal_enabled' => false, 'resolution_required' => false,
                    'confirmation_version' => DB::raw('confirmation_version + 1'), 'updated_at' => now(),
                ]);
            } elseif (($session['status'] ?? null) !== 'open') {
                throw new RuntimeException('BILLING_CHECKOUT_UNRESOLVED');
            }

            return null;
        });
        if ($providerId) {
            $this->syncSubscription($providerId);
        }
    }

    public function syncRefunds(string $chargeId): void
    {
        $payment = DB::table('billing_payments')->where(ProviderScope::legacyStripe()->columns())->where('provider_charge_id', $chargeId)->first();
        if (! $payment) {
            throw new RuntimeException('BILLING_PAYMENT_NOT_FOUND');
        }
        $cancellationVersion = $this->guarded($payment->subscription_id, function () use ($payment, $chargeId) {
            BillingStore::lock($payment->viewer_id);
            $payment = DB::table('billing_payments')->where('id', $payment->id)->first();
            $newSuccessfulRefund = false;
            $refunds = $this->gateway->refunds($chargeId);
            foreach ($refunds as $refund) {
                $this->requireFact(is_string($refund['id'] ?? null) && ($refund['currency'] ?? null) === $payment->currency
                    && is_int($refund['amount_minor'] ?? null) && $refund['amount_minor'] > 0
                    && in_array($refund['status'] ?? '', ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'], true));
                $existing = DB::table('billing_refunds')->where(ProviderScope::legacyStripe()->columns())->where('provider_refund_id', $refund['id'])->first();
                $this->requireFact(! $existing || ($existing->payment_id === $payment->id && $existing->amount_minor === $refund['amount_minor']));
                if ($refund['status'] === 'succeeded' && (! $existing || $existing->status !== 'succeeded')) {
                    $newSuccessfulRefund = true;
                }
                DB::table('billing_refunds')->updateOrInsert(ProviderScope::legacyStripe()->columns() + ['provider_refund_id' => $refund['id']], [
                    'payment_id' => $payment->id, 'amount_minor' => $refund['amount_minor'],
                    'status' => $refund['status'], 'created_at' => $existing->created_at ?? now(),
                ]);
            }
            // D04: corrections can lower the total; event payloads never determine a refund's current state.
            $total = (int) DB::table('billing_refunds')->where('payment_id', $payment->id)->where('status', 'succeeded')->sum('amount_minor');
            $this->requireFact($total <= $payment->amount_minor);
            DB::table('billing_payments')->where('id', $payment->id)->update(['refunded_amount_minor' => $total]);
            if ($total === $payment->amount_minor) {
                DB::table('billing_entitlements')->where('payment_id', $payment->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            } elseif ($payment->refunded_amount_minor === $payment->amount_minor) {
                // Restore only this payment's original [from, until); no dates, discount or renewal are changed.
                DB::table('billing_entitlements')->where('payment_id', $payment->id)->update(['revoked_at' => null]);
            }
            $order = DB::table('billing_orders')->where('id', $payment->order_id)->first();
            $review = $order->state === 'review_required' && $order->last_error !== 'PARTIAL_REFUND_REVIEW';
            DB::table('billing_orders')->where('id', $payment->order_id)->update([
                'state' => $review ? 'review_required' : 'paid',
                'payment_state' => $total === $payment->amount_minor ? 'refunded' : ($total > 0 ? 'partially_refunded' : 'succeeded'),
                'last_error' => $review ? $order->last_error : null, 'updated_at' => now(),
            ]);
            if ($newSuccessfulRefund) {
                $sub = DB::table('billing_subscriptions')->where('id', $payment->subscription_id)->first();
                // Persist the cancellation intent atomically with the new refund fact. A later user resume
                // clears this intent; replaying the same already-succeeded refund must never recreate it.
                $changes = ['refund_stopped' => true, 'desired_renewal' => false,
                    'confirmation_version' => DB::raw('confirmation_version + 1'), 'updated_at' => now()];
                if ($sub->state !== 'ended') {
                    $changes['resolution_required'] = true;
                }
                DB::table('billing_subscriptions')->where('id', $payment->subscription_id)->update($changes);
            }

            return $newSuccessfulRefund ? $sub->confirmation_version + 1 : null;
        });
        // A crash or timeout cannot lose the saved intent. The engine rechecks this version while
        // reserving its durable operation, before any platform call; older callbacks cannot cancel a later resume.
        if ($cancellationVersion !== null) {
            app(BillingEngine::class)->cancelSubscriptionForRefund($payment->subscription_id, $cancellationVersion);
        }
    }

    private function matchSubscription(object $sub, array $provider): void
    {
        $this->requireFact(ProviderScope::legacyStripe()->matches($sub) && ($provider['order_id'] ?? null) === $sub->first_order_id
            && is_string($provider['customer_id'] ?? null) && $provider['customer_id'] !== ''
            && ($sub->provider_id === null || $sub->provider_id === ($provider['id'] ?? null))
            && ($sub->customer_id === null || $sub->customer_id === $provider['customer_id'])
            && ($provider['regular_amount_minor'] ?? null) === $sub->regular_amount_minor
            && ($provider['currency'] ?? null) === $sub->currency
            && ($provider['is_test'] ?? null) === (bool) $sub->is_test);
    }

    private function applySubscription(object $sub, array $provider, bool $invoiceTerminal = false): void
    {
        $status = $provider['status'];
        $terminal = in_array($status, ['canceled', 'incomplete_expired'], true);
        $hasPaid = DB::table('billing_payments')->where('subscription_id', $sub->id)->exists();
        $hasFutureAccess = DB::table('billing_entitlements')->where('subscription_id', $sub->id)->whereNull('revoked_at')->where('access_until', '>', now())->exists();
        $latestPaid = ! empty($provider['latest_invoice_id']) && DB::table('billing_payments')->where(ProviderScope::legacyStripe()->columns())->where('subscription_id', $sub->id)->where('provider_invoice_id', $provider['latest_invoice_id'])->exists();
        $terminalResolved = $latestPaid || $invoiceTerminal;
        $renewal = ! $terminal && ! (bool) $provider['cancel_at_period_end'];
        $state = match (true) {
            $terminal && ! $terminalResolved => $hasPaid ? 'past_due' : 'pending',
            $terminal => $hasFutureAccess ? 'canceling' : 'ended',
            in_array($status, ['past_due', 'unpaid'], true) => 'past_due',
            $status === 'incomplete' => 'pending',
            $status === 'active' && ! $latestPaid => $hasPaid ? 'past_due' : 'pending',
            $status === 'active' && ! $renewal => 'canceling',
            $status === 'active' => 'active',
            default => 'unknown',
        };
        $hasPending = DB::table('billing_operations')->where('subscription_id', $sub->id)->where('state', 'pending')->exists();
        $review = DB::table('billing_orders')->where('subscription_id', $sub->id)->where('state', 'review_required')->exists();
        $update = [
            'provider_id' => $provider['id'], 'customer_id' => $provider['customer_id'], 'provider_status' => $status,
            'period_start' => $provider['period_start'], 'period_end' => $provider['period_end'],
            'resolution_required' => $hasPending || $review || ($terminal && ! $terminalResolved) || ((bool) $sub->refund_stopped && $renewal) || (! $terminal && (! $latestPaid || ! in_array($status, ['active'], true))),
            'updated_at' => now(),
        ];
        if (! $hasPending) {
            $update['state'] = $state;
            $update['renewal_enabled'] = $renewal;
            if ($state !== $sub->state || $renewal !== (bool) $sub->renewal_enabled
                || BillingStore::iso($provider['period_end']) !== BillingStore::iso($sub->period_end)) {
                $update['confirmation_version'] = DB::raw('confirmation_version + 1');
            }
        }
        DB::table('billing_subscriptions')->where('id', $sub->id)->update($update);
    }

    private function requireFact(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('BILLING_FACT_MISMATCH');
        }
    }

    private function guarded(string $subscriptionId, callable $operation): mixed
    {
        try {
            return DB::transaction($operation, attempts: 3);
        } catch (RuntimeException $error) {
            if ($error->getMessage() === 'BILLING_FACT_MISMATCH') {
                DB::transaction(function () use ($subscriptionId) {
                    $sub = DB::table('billing_subscriptions')->where('id', $subscriptionId)->first();
                    if (! $sub) {
                        return;
                    }
                    BillingStore::lock($sub->viewer_id);
                    DB::table('billing_subscriptions')->where('id', $sub->id)->update(['resolution_required' => true, 'updated_at' => now()]);
                    DB::table('billing_orders')->where('id', $sub->first_order_id)->update([
                        'state' => 'review_required', 'last_error' => 'BILLING_FACT_MISMATCH', 'updated_at' => now(),
                    ]);
                });
            }
            throw $error;
        }
    }
}
