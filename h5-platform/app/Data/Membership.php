<?php

namespace App\Data;

use App\Enums\SubscriptionAction;
use App\Enums\SubscriptionState;

final readonly class Membership extends ContractData
{
    /**
     * @param  list<SubscriptionAction>  $allowed_actions
     */
    public function __construct(
        public bool $access_active,
        public ?string $access_from,
        public ?string $access_until,
        public bool $renewal_enabled,
        public SubscriptionState $subscription_state,
        public bool $payment_resolution_required,
        public ?string $next_renewal_at,
        public ?int $next_renewal_amount_minor,
        public array $allowed_actions
    ) {}

    protected static function types(): array
    {
        return ['subscription_state' => SubscriptionState::class, 'allowed_actions' => [SubscriptionAction::class]];
    }
}
