<?php

namespace App\Modules\Billing\Services;

use App\Data\PaymentSettled;
use App\Enums\TransactionKind;
use App\Modules\Billing\Gateway\PayPal\LiveApi;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Support\ProviderActivity;
use App\Support\RuntimeEnvironmentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class OutboxService
{
    public function __construct(private Dispatcher $events) {}

    public function dispatchPending(int $limit = 100): int
    {
        $sent = 0;
        $ids = DB::table('billing_outbox')->whereIn('payment_id', ProviderActivity::eligible(DB::table('billing_payments'))->select('id'))->whereNull('delivered_at')->orderByRaw('last_attempted_at ASC NULLS FIRST')->orderBy('created_at')->limit(max(1, min($limit, 1000)))->pluck('id');
        foreach ($ids as $id) {
            try {
                $delivered = DB::transaction(function () use ($id) {
                    $row = DB::table('billing_outbox')->where('id', $id)->lockForUpdate()->first();
                    $payment = $row ? DB::table('billing_payments')->find($row->payment_id) : null;
                    if (! $row || $row->delivered_at
                        || ! $payment || ProviderActivity::disabled($payment->provider, $payment->provider_environment)) {
                        return false;
                    }
                    if (! $this->events->hasListeners(PaymentSettled::class)) {
                        DB::table('billing_outbox')->where('id', $id)->update(['last_error' => 'CONSUMER_NOT_READY', 'last_attempted_at' => now()]);

                        return false;
                    }
                    $event = PaymentSettled::fromArray(BillingStore::json($row->payload));
                    $responses = $this->events->dispatch($event);
                    if (is_array($responses) && in_array(false, $responses, true)) {
                        throw new RuntimeException('CONSUMER_REJECTED');
                    }
                    $this->markDelivered($id);

                    return true;
                });
                if ($delivered) {
                    $sent++;
                }
            } catch (Throwable) {
                DB::table('billing_outbox')->where('id', $id)->whereNull('delivered_at')->update([
                    'attempts' => DB::raw('attempts + 1'), 'last_error' => 'CONSUMER_RETRY_REQUIRED', 'last_attempted_at' => now(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * Invoke only from the approved exact-payment entry point. The receiver is local
     * and returns true only after confirming the matching receipt and delivery.
     *
     * @param  callable(PaymentSettled, array): bool  $receive
     * @return 'delivered'|'already_delivered'
     */
    public function dispatchProductionPilotPayment(string $paymentId, callable $receive): string
    {
        $guard = app(RuntimeEnvironmentGuard::class);
        $this->require(config('h5.acquisition_production_pilot') === true && Str::isUuid($paymentId));
        $guard->productionAcquisitionPilotOrigin();
        $approved = $guard->productionAcquisitionScope();
        $scope = new ProviderScope('paypal', 'live', config('billing.paypal_live.merchant_scope', ''));
        $this->require(! ProviderActivity::disabled('paypal', 'live'));
        (new PayPalMerchantBinding(app(LiveApi::class)))->assertBound($scope);

        return DB::transaction(function () use ($paymentId, $receive, $guard, $approved, $scope) {
            $payment = DB::table('billing_payments')->where($scope->columns())->find($paymentId);
            $this->require($payment !== null);
            // Share the existing financial lock order without creating account rows.
            $account = DB::table('billing_accounts')->where('viewer_id', $payment->viewer_id)->lockForUpdate()->first();
            $row = DB::table('billing_outbox')->where('payment_id', $paymentId)->lockForUpdate()->first();
            $payment = DB::table('billing_payments')->where($scope->columns())->find($paymentId);
            $this->require($account !== null && $row !== null && $payment !== null && $account->viewer_id === $payment->viewer_id);
            $event = $this->productionPilotEvent($row, $payment, $account, $scope, $approved);
            $this->require($guard->productionAcquisitionScope() === $approved);
            if ($row->delivered_at !== null) {
                return 'already_delivered';
            }
            if ($receive($event, $approved) !== true) {
                throw new RuntimeException('BILLING_PILOT_RECEIVER_NOT_CONFIRMED');
            }
            $this->require($guard->productionAcquisitionScope() === $approved);
            // Verified notices can arrive without taking the billing account lock.
            $this->requireNoBlockingProviderNotice($scope, $payment->provider_charge_id);
            $this->markDelivered($row->id);

            return 'delivered';
        });
    }

    private function productionPilotEvent(object $row, object $payment, object $account, ProviderScope $scope, array $approved): PaymentSettled
    {
        $order = DB::table('billing_orders')->where($scope->columns())->find($payment->order_id);
        $subscription = DB::table('billing_subscriptions')->where($scope->columns())->find($payment->subscription_id);
        $this->require($order !== null && $subscription !== null
            && $order->viewer_id === $payment->viewer_id && $subscription->viewer_id === $payment->viewer_id
            && $order->subscription_id === $subscription->id && $subscription->first_order_id === $order->id
            && $order->state === 'paid' && $order->payment_state === 'succeeded'
            && $order->transaction_kind === TransactionKind::FirstSubscription->value
            && ! $payment->is_test && ! $order->is_test && ! $subscription->is_test
            && $payment->currency === 'USD' && $order->currency === 'USD' && $subscription->currency === 'USD'
            && $payment->amount_minor > 0 && $payment->amount_minor === $order->amount_minor
            && $payment->amount_minor === $subscription->first_week_amount_minor
            && $order->price_version === $subscription->price_version
            && is_string($payment->provider_charge_id) && $payment->provider_charge_id !== ''
            && $payment->provider_charge_id === $payment->provider_invoice_id
            && $account->first_paid_at !== null && CarbonImmutable::parse($account->first_paid_at)->equalTo($payment->paid_at));
        $this->require($payment->refunded_amount_minor === 0 && ! $subscription->refund_stopped && ! $subscription->resolution_required
            && ! DB::table('billing_refunds')->where('payment_id', $payment->id)->whereIn('status', ['pending', 'succeeded'])->exists());
        $this->requireNoBlockingProviderNotice($scope, $payment->provider_charge_id);
        $entitlement = DB::table('billing_entitlements')->where('payment_id', $payment->id)->first();
        $this->require($entitlement !== null && $entitlement->viewer_id === $payment->viewer_id
            && $entitlement->subscription_id === $subscription->id && $entitlement->revoked_at === null
            && CarbonImmutable::parse($entitlement->access_from)->equalTo($payment->period_start)
            && CarbonImmutable::parse($entitlement->access_until)->equalTo($payment->period_end)
            && CarbonImmutable::parse($entitlement->access_from)->lte(now())
            && CarbonImmutable::parse($entitlement->access_until)->gt(now()));

        $snapshot = BillingStore::json($order->attribution_snapshot);
        $this->require(is_array($snapshot) && ($snapshot['link_id'] ?? null) === $approved['link_id']
            && ($snapshot['channel'] ?? null) === $approved['platform']
            && ($snapshot['pixel_config_version'] ?? null) === $approved['pixel_version']
            && ($snapshot['consent'] ?? null) === 'granted'
            && Str::isUuid($snapshot['source_id'] ?? '') && Str::isUuid($snapshot['checkout_context_id'] ?? ''));
        foreach ([$snapshot['captured_at'] ?? null, $order->created_at, $payment->paid_at, $row->created_at] as $value) {
            $this->require(is_string($value));
            $time = CarbonImmutable::parse($value);
            $this->require($time->gte($approved['from']) && $time->lt($approved['until']) && $time->lte(now()));
        }
        $payload = BillingStore::json($row->payload);
        $expected = [
            'event_id' => $row->id, 'schema_version' => 'PaymentSettled.v1', 'payment_id' => $payment->id,
            'order_id' => $order->id, 'subscription_id' => $subscription->id,
            'transaction_kind' => TransactionKind::FirstSubscription->value, 'discount_applied' => (bool) $order->discount_applied,
            'amount_minor' => $payment->amount_minor, 'currency' => $payment->currency,
            'occurred_at' => BillingStore::iso($payment->paid_at), 'is_test' => false, 'attribution_snapshot' => $snapshot,
        ];
        $this->require(is_array($payload) && Arr::sortRecursive($payload) === Arr::sortRecursive($expected));

        return PaymentSettled::fromArray($payload);
    }

    private function requireNoBlockingProviderNotice(ProviderScope $scope, string $saleId): void
    {
        $this->require(! DB::table('billing_inbox')->where($scope->columns())
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('event_type', 'PAYMENT.SALE.REVERSED')->where('object_id', $saleId))
                // A pending Refund points to a Refund ID. The Sale can be unknown locally.
                ->orWhere(fn ($q) => $q->where('event_type', 'PAYMENT.SALE.REFUNDED')->whereIn('state', ['pending', 'processing'])
                    ->where(fn ($q) => $q->whereNull('related_object_id')->orWhere('related_object_id', $saleId))))
            ->exists());
    }

    private function markDelivered(string $id): void
    {
        DB::table('billing_outbox')->where('id', $id)->update([
            'delivered_at' => now(), 'last_attempted_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'last_error' => null,
        ]);
    }

    private function require(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('BILLING_PILOT_PAYMENT_NOT_ELIGIBLE');
        }
    }
}
