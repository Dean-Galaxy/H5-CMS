<?php

namespace App\Contracts;

use App\Data\AttributionSnapshot;
use App\Data\CheckoutPageContext;

/** Internal trusted-page handoff; never accepts a raw browser URL or grants a purpose. */
interface CheckoutAttribution
{
    public function capture(AttributionSnapshot $source, CheckoutPageContext $page): ?AttributionSnapshot;
}
