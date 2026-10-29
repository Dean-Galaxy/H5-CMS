<?php

namespace App\Support;

use App\Contracts\BillingAnalyticsReader;

final class UnavailableBillingAnalyticsReader implements BillingAnalyticsReader
{
    public function payments(array $paymentIds): array
    {
        throw new DependencyNotReady('Billing analytics facts');
    }
}
