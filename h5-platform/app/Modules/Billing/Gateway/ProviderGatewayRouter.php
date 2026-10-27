<?php

namespace App\Modules\Billing\Gateway;

use App\Support\DependencyNotReady;

/** Dispatch by persisted account scope. Never fall back to a different provider. */
final class ProviderGatewayRouter
{
    public function __construct(private PaymentGateway $legacyStripe) {}

    public function forRecord(object $record): PaymentGateway
    {
        return $this->forScope(ProviderScope::fromRecord($record));
    }

    public function forScope(ProviderScope $scope): PaymentGateway
    {
        if ($scope->columns() === ProviderScope::legacyStripe()->columns()) {
            return $this->legacyStripe;
        }

        // The PayPal fact/period adapter is not yet ready; raw Stripe-shaped arrays are not a substitute.
        throw new DependencyNotReady('Billing / provider account adapter');
    }
}
