<?php

namespace App\Modules\Acquisition\Sending;

interface PlatformAdapter
{
    public function payload(object $delivery, object $receipt, array $context, ?string $testCode): array;

    public function send(object $delivery, array $payload, string $token): DeliveryResult;
}
