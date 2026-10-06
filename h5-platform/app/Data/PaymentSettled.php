<?php

namespace App\Data;

use App\Enums\TransactionKind;

final readonly class PaymentSettled extends ContractData
{
    public function __construct(
        public string $event_id,
        public string $schema_version,
        public string $payment_id,
        public string $order_id,
        public string $subscription_id,
        public TransactionKind $transaction_kind,
        public bool $discount_applied,
        public int $amount_minor,
        public string $currency,
        public string $occurred_at,
        public bool $is_test,
        public ?AttributionSnapshot $attribution_snapshot
    ) {}

    protected static function types(): array
    {
        return ['transaction_kind' => TransactionKind::class, 'attribution_snapshot' => AttributionSnapshot::class];
    }
}
