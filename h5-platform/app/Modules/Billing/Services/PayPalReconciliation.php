<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Gateway\PayPal\PayPalAmount;
use App\Modules\Billing\Gateway\PayPal\SandboxApi;
use App\Modules\Billing\Gateway\PayPal\SandboxSubscriptionRequest;
use App\Modules\Billing\Gateway\PayPal\SandboxWeeklyPlan;
use App\Modules\Billing\Gateway\PayPal\WeeklyCycleEvidence;
use App\Modules\Billing\Gateway\PayPal\WeeklyPlanVerifier;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Support\ProviderActivity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Authoritative reads only; no activate, debt capture or refund creation. */
final class PayPalReconciliation
{
    public function __construct(private SandboxApi $api, private PaymentLedger $ledger) {}

    public function assertAvailable(object $sub): void
    {
        $this->api->configurationStamp(ProviderScope::fromRecord($sub));
    }

    public function sync(string $subscriptionId, bool $allowLifecycleChanges = true): void
    {
        ProviderActivity::assertEnabled('paypal');
        $stage = 'IDENTITY';
        try {
            DB::transaction(function () use ($subscriptionId, $allowLifecycleChanges, &$stage) {
                $sub = DB::table('billing_subscriptions')->find($subscriptionId) ?? throw new RuntimeException('PAYPAL_SUBSCRIPTION_UNKNOWN');
                BillingStore::lock($sub->viewer_id);
                $sub = DB::table('billing_subscriptions')->find($subscriptionId);
                [$order, $remote, $scope, $stamp] = $this->read($sub);
                $status = $remote['status'];
                $expiredApproval = in_array($status, ['APPROVAL_PENDING', 'APPROVED'], true)
                    && $order->checkout_prepared_at && now()->gte(CarbonImmutable::parse($order->checkout_prepared_at)->addMinutes(31));
                $paidUntil = DB::table('billing_payments')->where('subscription_id', $sub->id)->max('period_end');
                $expiredSuspension = $status === 'SUSPENDED' && $sub->desired_renewal === false
                    && ($paidUntil === null || now()->gte(CarbonImmutable::parse($paidUntil)));
                if ($allowLifecycleChanges && ($expiredApproval || $expiredSuspension)) {
                    $this->api->cancel($sub->provider_id);
                    [$order, $remote, $scope, $stamp] = $this->read($sub);
                    $this->require(in_array($remote['status'], ['CANCELLED', 'EXPIRED'], true));
                    $status = $remote['status'];
                }
                $stage = 'TRANSACTIONS';
                $transactions = $this->transactions($sub, $order);
                $this->sameConfiguration($scope, $stamp);
                $known = DB::table('billing_payments')->where('subscription_id', $sub->id)->get()->keyBy('provider_charge_id');
                $unknown = array_filter($transactions, fn ($t) => ! isset($known[$t['id'] ?? '']));
                if ($unknown) {
                    $stage = 'PLAN';
                    $this->verifyPlan($sub, $order, $remote);
                    $stage = 'SALES';
                    $sales = [];
                    foreach ($transactions as $transaction) {
                        $id = $transaction['id'] ?? null;
                        $this->require(is_string($id));
                        $sales[$id] = $this->api->sale($id);
                    }
                    $stage = 'STABLE_READ';
                    // Detect state/cycle changes across the multi-read observation.
                    $again = $this->api->subscription($sub->provider_id);
                    $this->sameConfiguration($scope, $stamp);
                    foreach (['id', 'custom_id', 'plan_id', 'start_time', 'status', 'billing_info', 'subscriber', 'plan_overridden'] as $key) {
                        $this->require(($again[$key] ?? null) === ($remote[$key] ?? null));
                    }
                    $stage = 'CYCLE';
                    $facts = (new WeeklyCycleEvidence)->payments($sub, $order, $remote, $transactions, $sales, $known->all());
                    $stage = 'LEDGER';
                    foreach ($facts as $fact) {
                        $this->observe($sub, 'paid_cycle', $fact->reference, [
                            'period_start' => BillingStore::iso($fact->periodStart), 'period_end' => BillingStore::iso($fact->periodEnd),
                            'paid_at' => BillingStore::iso($fact->paidAt), 'amount_minor' => $fact->amountMinor, 'currency' => 'USD',
                            'start_time' => $remote['start_time'], 'next_billing_time' => $remote['billing_info']['next_billing_time'],
                            'cycle_executions' => $remote['billing_info']['cycle_executions'], 'transaction_count' => count($transactions),
                        ]);
                        $this->ledger->record($sub->id, $fact);
                    }
                }
                $stage = 'HISTORY';
                $known = DB::table('billing_payments')->where('subscription_id', $sub->id)->get()->keyBy('provider_charge_id');
                $this->require(count($transactions) === count($known));
                foreach ($transactions as $transaction) {
                    $payment = $known[$transaction['id'] ?? ''] ?? null;
                    $this->require($payment !== null && PayPalAmount::minor($transaction['amount_with_breakdown']['gross_amount']['value'] ?? null,
                        $transaction['amount_with_breakdown']['gross_amount']['currency_code'] ?? null) === $payment->amount_minor);
                    // A changed transaction state must go through the Sale/refund reader first.
                    if (($transaction['status'] ?? null) !== 'COMPLETED') {
                        $this->syncRefunds($payment->id);
                    }
                }
                // A lagging transaction list must not release occupancy after a newer payment.
                $again = $this->api->subscription($sub->provider_id);
                $this->sameConfiguration($scope, $stamp);
                foreach (['id', 'custom_id', 'plan_id', 'start_time', 'status', 'billing_info', 'subscriber'] as $key) {
                    $this->require(($again[$key] ?? null) === ($remote[$key] ?? null));
                }
                $hasAccess = DB::table('billing_entitlements')->where('subscription_id', $sub->id)->whereNull('revoked_at')->where('access_until', '>', now())->exists();
                $latest = $known->sortByDesc('period_end')->first();
                $terminal = in_array($status, ['CANCELLED', 'EXPIRED'], true);
                $stopped = $terminal || $status === 'SUSPENDED';
                $info = $remote['billing_info'] ?? [];
                $debt = isset($info['outstanding_balance']) ? PayPalAmount::minor($info['outstanding_balance']['value'] ?? null, $info['outstanding_balance']['currency_code'] ?? null) : 0;
                $failed = ($info['failed_payments_count'] ?? 0) > 0 || $debt > 0;
                $review = DB::table('billing_orders')->where('subscription_id', $sub->id)->where('state', 'review_required')->exists();
                $pending = DB::table('billing_operations')->where('subscription_id', $sub->id)->where('state', 'pending')->exists();
                $paidCurrent = $latest && CarbonImmutable::parse($latest->period_end)->gt(now());
                $zeroCycles = isset($info['cycle_executions']) && is_array($info['cycle_executions'])
                    && count($info['cycle_executions']) > 0;
                foreach ($info['cycle_executions'] ?? [] as $cycle) {
                    $zeroCycles = $zeroCycles && ($cycle['cycles_completed'] ?? null) === 0;
                }
                $unpaidTerminalProven = $zeroCycles && ! isset($info['last_payment']) && isset($info['outstanding_balance']) && ! $failed;
                $historyMatches = ! $known->count() || $this->knownHistoryMatches($info, $known->count(), $latest);
                $sub = DB::table('billing_subscriptions')->find($sub->id); // refund reads can persist a stop intent
                $resolution = ! $historyMatches || $pending || $review || $failed || ($terminal && ! $known->count() && ! $unpaidTerminalProven) || (! $terminal && ! $paidCurrent)
                    || (($sub->desired_renewal === false || $sub->refund_stopped) && ! $stopped);
                DB::table('billing_subscriptions')->where('id', $sub->id)->update([
                    'state' => $terminal && ! $resolution ? ($hasAccess ? 'canceling' : 'ended')
                        : ($stopped ? 'canceling' : ($paidCurrent && ! $failed ? 'active' : (count($known) ? 'past_due' : 'pending'))),
                    'provider_status' => $status, 'renewal_enabled' => ! $stopped && $status === 'ACTIVE',
                    'resolution_required' => $resolution, 'period_start' => $latest?->period_start, 'period_end' => $latest?->period_end, 'updated_at' => now(),
                ]);
                if ($expiredApproval && ! $known->count() && ! $resolution) {
                    DB::table('billing_orders')->where('id', $order->id)->update(['state' => 'expired', 'checkout_url' => null, 'last_error' => null, 'updated_at' => now()]);
                }
            });
        } catch (Throwable $error) {
            // Preserve a reversal stop intent even when the observation transaction rolls back.
            $this->markPending($subscriptionId, $error->getMessage() === 'PAYPAL_SALE_REVIEW_REQUIRED', 'PAYPAL_RECONCILE_'.$stage);
            throw new RuntimeException('PAYPAL_RECONCILIATION_REQUIRED');
        }
    }

    /** Fresh official read only: a stored URL never proves that approval is still needed. */
    public function approvalUrl(object $order): ?string
    {
        return DB::transaction(function () use ($order) {
            BillingStore::lock($order->viewer_id);
            $order = DB::table('billing_orders')->find($order->id);
            $sub = DB::table('billing_subscriptions')->find($order->subscription_id);
            if ($order->state !== 'pending' || $order->payment_state !== 'pending' || $sub->state !== 'pending'
                || $sub->desired_renewal === false || $sub->refund_stopped || ! $order->checkout_prepared_at
                || now()->gte(CarbonImmutable::parse($order->checkout_prepared_at)->addMinutes(31))) {
                return null;
            }
            [, $remote, $scope, $stamp] = $this->read($sub);
            $prepared = BillingStore::json($order->checkout_request);
            $this->require(($prepared['params'] ?? null) == (new SandboxSubscriptionRequest($remote['plan_id'], $order->id, $order))->payload());
            $this->verifyPlan($sub, $order, $remote);
            $this->sameConfiguration($scope, $stamp);

            return SandboxSubscriptionRequest::approvalUrl($remote);
        });
    }

    /** Called while BillingEngine holds the account lock and current operation version. */
    public function cancelOperation(object $sub): void
    {
        [, $remote, $scope, $stamp] = $this->read($sub);
        if (! in_array($remote['status'], ['SUSPENDED', 'CANCELLED', 'EXPIRED'], true)) {
            if ($remote['status'] === 'ACTIVE') {
                $this->api->suspend($sub->provider_id);
            } else {
                $this->api->cancel($sub->provider_id);
            }
            [, $remote] = $this->read($sub);
        }
        $this->sameConfiguration($scope, $stamp);
        $this->require(in_array($remote['status'], ['SUSPENDED', 'CANCELLED', 'EXPIRED'], true));
        // Confirmation stops renewal only. Paid intervals stay exactly as originally recorded.
        DB::table('billing_subscriptions')->where('id', $sub->id)->update(['state' => 'canceling',
            'provider_status' => $remote['status'], 'renewal_enabled' => false, 'resolution_required' => true,
            'confirmation_version' => DB::raw('confirmation_version + 1'), 'updated_at' => now()]);
    }

    public function process(object $event): bool
    {
        ProviderActivity::assertEnabled('paypal');
        $scope = ProviderScope::fromRecord($event);
        $this->api->configurationStamp($scope);
        if (str_starts_with($event->event_type, 'BILLING.SUBSCRIPTION.')) {
            $sub = DB::table('billing_subscriptions')->where($scope->columns())->where('provider_id', $event->object_id)->first();
            $this->require($sub !== null);
            $this->sync($sub->id);
        } elseif ($event->event_type === 'PAYMENT.SALE.REFUNDED') {
            $this->refund($scope, $event->object_id);
        } elseif (in_array($event->event_type, ['PAYMENT.SALE.COMPLETED', 'PAYMENT.SALE.REVERSED'], true)) {
            $sale = $this->api->sale($event->object_id);
            $this->require(($sale['id'] ?? null) === $event->object_id);
            $sub = DB::table('billing_subscriptions')->where($scope->columns())->where('provider_id', $sale['billing_agreement_id'] ?? '')->first();
            $this->require($sub !== null);
            if ($event->event_type === 'PAYMENT.SALE.REVERSED') {
                $this->markPending($sub->id, true);
                throw new RuntimeException('PAYPAL_REVERSAL_REVIEW_REQUIRED');
            }
            $this->sync($sub->id);
        } else {
            return false;
        }

        return true;
    }

    public function refund(ProviderScope $scope, string $refundId): void
    {
        ProviderActivity::assertEnabled('paypal');
        $stamp = $this->api->configurationStamp($scope);
        $this->api->assertMerchant($scope);
        $refund = $this->api->refund($refundId);
        $this->sameConfiguration($scope, $stamp);
        $payment = DB::table('billing_payments')->where($scope->columns())->where('provider_charge_id', $refund['sale_id'] ?? '')->first();
        $this->require($payment !== null && ($refund['id'] ?? null) === $refundId);
        $amount = PayPalAmount::minor($refund['amount']['total'] ?? null, $refund['amount']['currency'] ?? null);
        $this->require($amount > 0 && $amount <= $payment->amount_minor && in_array($refund['state'] ?? null, ['pending', 'completed', 'failed', 'cancelled'], true));
        DB::transaction(function () use ($scope, $refundId, $refund, $amount, $payment) {
            BillingStore::lock($payment->viewer_id);
            $existing = DB::table('billing_refunds')->where($scope->columns())->where('provider_refund_id', $refundId)->first();
            $this->require(! $existing || ($existing->payment_id === $payment->id && $existing->amount_minor === $amount));
            DB::table('billing_refunds')->updateOrInsert($scope->columns() + ['provider_refund_id' => $refundId],
                ['payment_id' => $payment->id, 'amount_minor' => $amount, 'status' => $refund['state'] === 'completed' ? 'succeeded' : $refund['state'], 'created_at' => $existing->created_at ?? now()]);
        });
        $this->syncRefunds($payment->id);
        $sub = DB::table('billing_subscriptions')->find($payment->subscription_id);
        $pending = DB::table('billing_operations')->where('subscription_id', $sub->id)->where('state', 'pending')->exists();
        if (($sub->refund_stopped || $sub->desired_renewal === false) && (! $this->renewalStopConfirmed($sub) || $pending)) {
            app(BillingEngine::class)->cancelSubscriptionForRefund($sub->id);
        }
    }

    public function syncRefunds(string $paymentId): void
    {
        ProviderActivity::assertEnabled('paypal');
        $payment = DB::table('billing_payments')->find($paymentId) ?? throw new RuntimeException('PAYPAL_PAYMENT_UNKNOWN');
        $scope = ProviderScope::fromRecord($payment);
        $stamp = $this->api->configurationStamp($scope);
        $this->api->assertMerchant($scope);
        $refunds = [];
        foreach (DB::table('billing_refunds')->where('payment_id', $paymentId)->get() as $knownRefund) {
            $current = $this->api->refund($knownRefund->provider_refund_id);
            $this->require(($current['id'] ?? null) === $knownRefund->provider_refund_id && ($current['sale_id'] ?? null) === $payment->provider_charge_id
                && PayPalAmount::minor($current['amount']['total'] ?? null, $current['amount']['currency'] ?? null) === $knownRefund->amount_minor
                && in_array($current['state'] ?? null, ['pending', 'completed', 'failed', 'cancelled'], true));
            $refunds[$knownRefund->provider_refund_id] = $current['state'] === 'completed' ? 'succeeded' : $current['state'];
        }
        $sale = $this->api->sale($payment->provider_charge_id);
        $sub = DB::table('billing_subscriptions')->find($payment->subscription_id);
        $this->sameConfiguration($scope, $stamp);
        $this->require(($sale['id'] ?? null) === $payment->provider_charge_id && ($sale['billing_agreement_id'] ?? null) === $sub->provider_id
            && PayPalAmount::minor($sale['amount']['total'] ?? null, $sale['amount']['currency'] ?? null) === $payment->amount_minor);
        $state = $sale['state'] ?? null;
        if (! in_array($state, ['completed', 'partially_refunded', 'refunded'], true)) {
            $this->markPending($sub->id, true);
            throw new RuntimeException('PAYPAL_SALE_REVIEW_REQUIRED');
        }
        DB::transaction(function () use ($payment, $sub, $state, $refunds, $scope) {
            BillingStore::lock($sub->viewer_id);
            $currentSubscription = DB::table('billing_subscriptions')->find($sub->id);
            foreach ($refunds as $id => $status) {
                DB::table('billing_refunds')->where($scope->columns())->where('provider_refund_id', $id)->update(['status' => $status]);
            }
            $this->observe($sub, 'sale_refund_state', $payment->provider_charge_id, ['state' => $state, 'original_amount_minor' => $payment->amount_minor, 'currency' => 'USD']);
            $known = (int) DB::table('billing_refunds')->where('payment_id', $payment->id)->where('status', 'succeeded')->sum('amount_minor');
            $this->require($known <= $payment->amount_minor && ! ($state === 'completed' && $known > 0));
            // Authoritative Sale state 'refunded' proves the entire original Sale was refunded.
            // No synthetic refund ID is created when an individual refund notification is missing.
            $total = $state === 'refunded' ? $payment->amount_minor : $known;
            DB::table('billing_payments')->where('id', $payment->id)->update(['refunded_amount_minor' => $total]);
            DB::table('billing_entitlements')->where('payment_id', $payment->id)->update(['revoked_at' => $total === $payment->amount_minor ? now() : null]);
            DB::table('billing_orders')->where('id', $payment->order_id)->update(['payment_state' => $state === 'refunded' ? 'refunded' : ($state === 'partially_refunded' ? 'partially_refunded' : 'succeeded'), 'updated_at' => now()]);
            if ($state !== 'completed') {
                $changes = ['refund_stopped' => true, 'desired_renewal' => false, 'updated_at' => now()];
                // Preserve an already confirmed stop, and preserve any unrelated unresolved
                // check. Only a full subscription reconciliation may clear that check.
                if (! $this->renewalStopConfirmed($currentSubscription)) {
                    $changes['resolution_required'] = true;
                }
                DB::table('billing_subscriptions')->where('id', $sub->id)->update($changes);
            }
        });
        // A durable desired_renewal=false is consumed by the operation worker; never recurse from a read transaction.
    }

    private function renewalStopConfirmed(object $sub): bool
    {
        return $sub->desired_renewal === false && $sub->renewal_enabled === false
            && in_array($sub->provider_status, ['SUSPENDED', 'CANCELLED', 'EXPIRED'], true);
    }

    private function knownHistoryMatches(array $info, int $count, object $latest): bool
    {
        try {
            $cycles = $info['cycle_executions'] ?? null;
            if (! is_array($cycles) || ! $cycles) {
                return false;
            }
            $executed = 0;
            foreach ($cycles as $cycle) {
                $n = $cycle['cycles_completed'] ?? null;
                if (! is_int($n) || $n < 0) {
                    return false;
                }
                $executed += $n;
            }

            return $executed === $count
                && WeeklyCycleEvidence::time($info['last_payment']['time'] ?? null)->equalTo(CarbonImmutable::parse($latest->paid_at))
                && PayPalAmount::minor($info['last_payment']['amount']['value'] ?? null, $info['last_payment']['amount']['currency_code'] ?? null) === $latest->amount_minor;
        } catch (Throwable) {
            return false;
        }
    }

    private function read(object $sub): array
    {
        $scope = ProviderScope::fromRecord($sub);
        $stamp = $this->api->configurationStamp($scope);
        $order = DB::table('billing_orders')->find($sub->first_order_id);
        $this->api->assertMerchant($scope);
        $remote = $this->api->subscription($sub->provider_id);
        $this->sameConfiguration($scope, $stamp);
        $request = BillingStore::json($order?->checkout_request);
        $this->require($order !== null && $scope->matches($order) && $order->viewer_id === $sub->viewer_id && $order->subscription_id === $sub->id
            && ($remote['id'] ?? null) === $sub->provider_id && ($remote['custom_id'] ?? null) === $order->id
            && ($remote['plan_id'] ?? null) === ($request['params']['plan_id'] ?? null)
            && is_string($remote['plan_id'] ?? null) && ($sub->customer_id === null || ($remote['subscriber']['payer_id'] ?? null) === $sub->customer_id)
            && in_array($remote['status'] ?? null, ['APPROVAL_PENDING', 'APPROVED', 'ACTIVE', 'SUSPENDED', 'CANCELLED', 'EXPIRED'], true));

        return [$order, $remote, $scope, $stamp];
    }

    private function verifyPlan(object $sub, object $order, array $remote): void
    {
        $this->require(($remote['plan_overridden'] ?? null) === false && ($remote['quantity'] ?? null) === '1'
            && (! isset($remote['shipping_amount']) || PayPalAmount::minor($remote['shipping_amount']['value'] ?? null, $remote['shipping_amount']['currency_code'] ?? null) === 0));
        $prepared = BillingStore::json($order->checkout_request);
        $intent = DB::table('billing_provider_intents')->where(ProviderScope::fromRecord($sub)->columns())->where(['operation' => 'create_plan', 'owner_id' => $prepared['plan_owner_id'], 'state' => 'succeeded'])->first();
        $this->require($intent !== null && $intent->external_id === $remote['plan_id']);
        $payload = BillingStore::json($intent->request_payload);
        (new WeeklyPlanVerifier)->assertMatches($this->api->plan($remote['plan_id']), $remote['plan_id'],
            new SandboxWeeklyPlan($sub->price_version, $sub->regular_amount_minor, $sub->first_week_amount_minor, $order->discount_applied), $payload['product_id']);
    }

    private function transactions(object $sub, object $order): array
    {
        $from = CarbonImmutable::parse($order->created_at)->utc()->startOfSecond();
        $until = CarbonImmutable::now('UTC')->startOfSecond()->addSecond();
        $this->require($until->getTimestamp() - $from->getTimestamp() <= 2 * 366 * 86400);
        $rows = [];
        while ($from->lt($until)) {
            $end = $from->addDays(30)->min($until);
            $page = $this->api->transactions($sub->provider_id, $from->toDateTimeImmutable(), $end->toDateTimeImmutable());
            $items = $page['transactions'] ?? null;
            $this->require(is_array($items) && array_is_list($items)
                && (! array_key_exists('total_items', $page) || $page['total_items'] === count($items))
                && (! array_key_exists('total_pages', $page) || in_array($page['total_pages'], count($items) ? [1] : [0, 1], true))
                && (! array_key_exists('links', $page) || (is_array($page['links']) && array_is_list($page['links']))));
            foreach ($page['links'] ?? [] as $link) {
                $this->require(is_array($link) && is_string($link['rel'] ?? null) && strtolower($link['rel']) !== 'next');
            }
            foreach ($items as $item) {
                $id = $item['id'] ?? null;
                $this->require(is_string($id) && preg_match('/\A[A-Z0-9]{1,128}\z/D', $id) === 1);
                $time = WeeklyCycleEvidence::time($item['time'] ?? null);
                $this->require($time->gte($from) && $time->lte($end));
                $this->require(! isset($rows[$id]) || $rows[$id] === $item);
                $rows[$id] = $item;
            }
            $from = $end;
        }

        return array_values($rows);
    }

    private function observe(object $sub, string $kind, string $externalId, array $facts): void
    {
        $fingerprint = hash('sha256', json_encode([$sub->id, $sub->merchant_scope, $kind, $externalId, $facts], JSON_THROW_ON_ERROR));
        DB::table('billing_paypal_observations')->insertOrIgnore(['id' => BillingStore::id(), 'subscription_id' => $sub->id,
            'merchant_scope' => $sub->merchant_scope, 'kind' => $kind, 'external_id' => $externalId,
            'fingerprint' => $fingerprint, 'facts' => json_encode($facts, JSON_THROW_ON_ERROR), 'observed_at' => now()]);
    }

    private function markPending(string $subId, bool $stop = false, ?string $diagnostic = null): void
    {
        $sub = DB::table('billing_subscriptions')->find($subId);
        if (! $sub) {
            return;
        }
        DB::transaction(function () use ($sub, $stop, $diagnostic) {
            BillingStore::lock($sub->viewer_id);
            if ($diagnostic !== null) {
                DB::table('billing_orders')->where('id', $sub->first_order_id)->whereIn('state', ['processing', 'pending'])
                    ->update(['last_error' => $diagnostic, 'updated_at' => now()]);
            }
            DB::table('billing_subscriptions')->where('id', $sub->id)->update(['resolution_required' => true, 'updated_at' => now()]
                + ($stop ? ['desired_renewal' => false] : []));
        });
    }

    private function sameConfiguration(ProviderScope $scope, string $stamp): void
    {
        $this->require(hash_equals($stamp, $this->api->configurationStamp($scope)));
    }

    private function require(bool $valid): void
    {
        if (! $valid) {
            throw new RuntimeException('PAYPAL_FACT_RECONCILIATION_REQUIRED');
        }
    }
}
