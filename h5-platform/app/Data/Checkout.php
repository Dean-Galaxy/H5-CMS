<?php

namespace App\Data;

use App\Enums\OrderState;

final readonly class Checkout extends ContractData
{
    public function __construct(
        public string $order_id,
        public OrderState $state,
        public ?string $checkout_url,
        public ?string $expires_at
    ) {}

    protected static function types(): array
    {
        return ['state' => OrderState::class];
    }
}
