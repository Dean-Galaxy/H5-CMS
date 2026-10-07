<?php

namespace App\Modules\Billing\Gateway;

interface PaymentGateway
{
    public function assertAvailable(): void;

    public function prepareCheckout(object $order, object $subscription): array;

    public function createCheckout(object $order, object $subscription): array;

    public function checkout(string $sessionId): array;

    public function subscription(string $id): array;

    public function setRenewal(object $subscription, bool $enabled, string $idempotencyKey): array;

    public function invoice(string $id): array;

    public function refunds(string $chargeId): array;

    public function verifyEvent(string $payload, string $signature): array;

    public function invoices(string $subscriptionId): array;
}
