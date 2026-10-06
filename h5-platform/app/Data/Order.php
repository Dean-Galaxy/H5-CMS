<?php

namespace App\Data;

use App\Enums\OrderState;
use App\Enums\PaymentState;

final readonly class Order extends ContractData
{
    public function __construct(
        public string $order_id,
        public OrderState $state,
        public PaymentState $payment_state,
        public int $amount_minor,
        public string $currency,
        public string $created_at
    ) {}

    protected static function types(): array
    {
        return ['state' => OrderState::class, 'payment_state' => PaymentState::class];
    }
}
