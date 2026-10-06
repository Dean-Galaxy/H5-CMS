<?php

namespace App\Contracts;

use App\Data\AttributionSnapshot;
use App\Data\Checkout;
use App\Data\CheckoutInput;
use App\Data\Membership;
use App\Data\Offer;
use App\Data\Order;
use App\Data\ResumeInput;
use App\Data\StaffIdentity;
use App\Data\ViewerIdentity;

interface BillingService
{
    public function offer(?ViewerIdentity $viewer): Offer;

    public function checkout(ViewerIdentity $viewer, CheckoutInput $input, string $idempotencyKey, ?AttributionSnapshot $source): Checkout;

    public function order(ViewerIdentity $viewer, string $orderId): Order;

    public function cancel(ViewerIdentity $viewer): Membership;

    public function resume(ViewerIdentity $viewer, ResumeInput $confirmation): Membership;

    /** Staff cancellation requires a provider-owned case record with verified identity and intent. */
    public function cancelForSupport(StaffIdentity $staff, string $viewerId, string $verifiedCaseId): Membership;
}
