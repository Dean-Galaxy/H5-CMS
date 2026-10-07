<?php

namespace App\Modules\Billing\Gateway;

use App\Support\DependencyNotReady;

final class UnconfiguredGateway implements PaymentGateway
{
    public function assertAvailable(): void
    {
        throw new DependencyNotReady('Billing / Stripe 未配置');
    }

    public function createCheckout(object $order, object $subscription): array
    {
        $this->assertAvailable();
    }

    public function checkout(string $sessionId): array
    {
        $this->assertAvailable();
    }

    public function subscription(string $id): array
    {
        $this->assertAvailable();
    }

    public function setRenewal(object $subscription, bool $enabled, string $idempotencyKey): array
    {
        $this->assertAvailable();
    }

    public function invoice(string $id): array
    {
        $this->assertAvailable();
    }

    public function refunds(string $chargeId): array
    {
        $this->assertAvailable();
    }

    public function verifyEvent(string $payload, string $signature): array
    {
        $this->assertAvailable();
    }

    public function invoices(string $subscriptionId): array
    {
        $this->assertAvailable();
    }
}
