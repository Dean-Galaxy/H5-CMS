<?php

namespace App\Modules\Acquisition\Sending;

final readonly class DeliveryResult
{
    public function __construct(public string $state, public ?string $error = null) {}
}
