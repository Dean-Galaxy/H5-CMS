<?php

namespace Tests\Feature\Billing\Support;

use App\Modules\Billing\Gateway\PaymentGateway;
use App\Support\ApiProblem;
use App\Support\DependencyNotReady;

final class FakeGateway implements PaymentGateway
{
    public array $subscriptions = [];

    public array $invoices = [];

    public array $checkouts = [];

    public array $refunds = [];

    public array $events = [];

    public bool $unavailable = false;

    public bool $failCreate = false;

    public bool $failRenewal = false;

    public int $createCalls = 0;

    public int $renewalCalls = 0;

    public function assertAvailable(): void
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Tests only');
        } if ($this->unavailable) {
            throw new DependencyNotReady('test');
        }
    }

    public function prepareCheckout(object $order, object $subscription): array
    {
        $this->assertAvailable();

        return ['params' => ['metadata' => ['order_id' => $order->id]], 'idempotency_key' => 'checkout-'.$order->id,
            'customer_id' => 'cus_'.$order->viewer_id, 'prepared_at' => now()->startOfSecond()->toIso8601ZuluString(),
            'expires_at' => now()->startOfSecond()->addSeconds(1860)->toIso8601ZuluString()];
    }

    public function createCheckout(object $order, object $subscription): array
    {
        $this->assertAvailable();
        $this->createCalls++;
        if ($this->failCreate) {
            throw new ApiProblem('BILLING_PENDING', 409, 'test timeout');
        }
        $id = 'cs_test_'.$order->id;
        $this->checkouts[$id] = ['id' => $id, 'status' => 'open', 'payment_status' => 'unpaid', 'subscription_id' => null, 'customer_id' => 'cus_'.$order->viewer_id, 'order_id' => $order->id, 'is_test' => true];

        return ['session_id' => $id, 'url' => 'https://checkout.stripe.com/c/pay/'.$id, 'expires_at' => $order->expires_at, 'customer_id' => 'cus_'.$order->viewer_id, 'subscription_id' => null];
    }

    public function checkout(string $id): array
    {
        return $this->checkouts[$id];
    }

    public function subscription(string $id): array
    {
        return $this->subscriptions[$id];
    }

    public function setRenewal(object $subscription, bool $enabled, string $idempotencyKey): array
    {
        $this->renewalCalls++;
        $this->subscriptions[$subscription->provider_id]['cancel_at_period_end'] = ! $enabled;
        if ($this->failRenewal) {
            throw new ApiProblem('BILLING_PENDING', 409, 'test after remote mutation timeout');
        }

        return $this->subscriptions[$subscription->provider_id];
    }

    public function invoice(string $id): array
    {
        return $this->invoices[$id];
    }

    public function refunds(string $id): array
    {
        return $this->refunds[$id] ?? [];
    }

    public function verifyEvent(string $payload, string $signature): array
    {
        if ($signature !== 'signed-test') {
            throw new ApiProblem('VALIDATION_FAILED', 400, 'invalid test signature');
        }

        return $this->events[$payload];
    }

    public function invoices(string $subscriptionId): array
    {
        return array_keys(array_filter($this->invoices, fn ($i) => $i['subscription_id'] === $subscriptionId));
    }
}
