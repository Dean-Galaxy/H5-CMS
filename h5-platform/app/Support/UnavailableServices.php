<?php

namespace App\Support;

use App\Contracts\BillingService;
use App\Contracts\CatalogReader;
use App\Contracts\MembershipReader;
use App\Contracts\PlaybackAuthorizer;
use App\Contracts\ProgressService;
use App\Data\AttributionSnapshot;
use App\Data\CatalogPage;
use App\Data\Checkout;
use App\Data\CheckoutInput;
use App\Data\Drama;
use App\Data\Membership;
use App\Data\Offer;
use App\Data\Order;
use App\Data\PlaybackGrant;
use App\Data\Progress;
use App\Data\ProgressInput;
use App\Data\ResumeInput;
use App\Data\StaffIdentity;
use App\Data\ViewerIdentity;

final class UnavailableServices implements BillingService, CatalogReader, MembershipReader, PlaybackAuthorizer, ProgressService
{
    public function catalog(?string $cursor = null, int $limit = 20, string $locale = 'en'): CatalogPage
    {
        throw new DependencyNotReady('Content');
    }

    public function drama(string $dramaId, string $locale = 'en'): Drama
    {
        throw new DependencyNotReady('Content');
    }

    public function membership(string $viewerId, \DateTimeImmutable $at): Membership
    {
        throw new DependencyNotReady('Billing');
    }

    public function offer(?ViewerIdentity $viewer): Offer
    {
        throw new DependencyNotReady('Billing');
    }

    public function checkout(ViewerIdentity $viewer, CheckoutInput $input, string $idempotencyKey, ?AttributionSnapshot $source): Checkout
    {
        throw new DependencyNotReady('Billing');
    }

    public function order(ViewerIdentity $viewer, string $orderId): Order
    {
        throw new DependencyNotReady('Billing');
    }

    public function cancel(ViewerIdentity $viewer): Membership
    {
        throw new DependencyNotReady('Billing');
    }

    public function resume(ViewerIdentity $viewer, ResumeInput $confirmation): Membership
    {
        throw new DependencyNotReady('Billing');
    }

    public function cancelForSupport(StaffIdentity $staff, string $viewerId, string $verifiedCaseId): Membership
    {
        throw new DependencyNotReady('Billing');
    }

    public function authorizePlayback(?ViewerIdentity $viewer, string $episodeId, string $region, ?string $playSessionId = null): PlaybackGrant
    {
        throw new DependencyNotReady('Content');
    }

    public function progress(ViewerIdentity $viewer, string $dramaId): ?Progress
    {
        throw new DependencyNotReady('Audience');
    }

    public function saveProgress(ViewerIdentity $viewer, string $dramaId, ProgressInput $input): Progress
    {
        throw new DependencyNotReady('Audience');
    }
}
