<?php

namespace App\Support;

use App\Contracts\CheckoutAttribution;
use App\Data\AttributionSnapshot;
use App\Data\CheckoutPageContext;

final class DisabledCheckoutAttribution implements CheckoutAttribution
{
    public function capture(AttributionSnapshot $source, CheckoutPageContext $page): ?AttributionSnapshot
    {
        return null;
    }
}
