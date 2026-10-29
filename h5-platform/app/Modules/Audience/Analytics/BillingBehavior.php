<?php

namespace App\Modules\Audience\Analytics;

use App\Contracts\BehaviorEventRecorder;
use App\Data\Checkout;
use App\Data\Order;
use App\Data\ViewerIdentity;
use App\Support\ApiProblem;

/** Only facts returned by Billing, never browser return flags or provider URLs. */
final class BillingBehavior
{
    public function checkout(Checkout $checkout, ViewerIdentity $viewer, bool $firstObserved = true): void
    {
        if ($firstObserved) {
            $this->record('checkout_created', ['viewer_id' => $viewer->id, 'order_id' => $checkout->order_id], 'checkout:'.$checkout->order_id);
        }
        $this->state($checkout->state->value, null, $checkout->order_id, $viewer);
    }

    public function order(Order $order, ViewerIdentity $viewer): void
    {
        $this->state($order->state->value, $order->payment_state->value, $order->order_id, $viewer);
    }

    public function rejected(ApiProblem $problem, string $attemptKey, ViewerIdentity $viewer): void
    {
        // Unknown outcomes are not failed payments. A later order read reports pending if known.
        if (in_array($problem->errorCode, ['BILLING_PENDING', 'SUBSCRIPTION_EXISTS'], true)) {
            return;
        }
        $code = match ($problem->errorCode) {
            'DEPENDENCY_NOT_READY', 'NOT_FOUND' => 'unavailable',
            'STALE_VERSION' => 'invalid',
            'REGION_BLOCKED' => 'region',
            'EMAIL_UNVERIFIED' => 'authorization',
            default => 'unknown',
        };
        $this->record('checkout_failed', ['viewer_id' => $viewer->id, 'error_code' => $code], 'checkout-failed:'.hash('sha256', $attemptKey.':'.$code));
    }

    private function state(string $state, ?string $payment, string $orderId, ViewerIdentity $viewer): void
    {
        $name = match (true) {
            $state === 'canceled' => 'payment_cancelled',
            $state === 'failed' || $payment === 'failed' => 'payment_failed',
            in_array($state, ['pending', 'processing', 'review_required'], true) && in_array($payment, [null, 'pending', 'unknown'], true) => 'payment_pending',
            default => null,
        };
        if ($name !== null) {
            $this->record($name, ['viewer_id' => $viewer->id, 'order_id' => $orderId], $name.':'.$orderId);
        }
    }

    private function record(string $event, array $properties, string $key): void
    {
        try {
            app(BehaviorEventRecorder::class)->record($event, $properties, 'audience:'.$key);
        } catch (\Throwable) {
            // Optional behavior collection must not change payment or playback outcomes.
        }
    }
}
