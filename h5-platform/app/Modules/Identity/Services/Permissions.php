<?php

namespace App\Modules\Identity\Services;

use App\Data\ResourceScope;
use App\Data\StaffIdentity;
use App\Enums\Permission as P;
use App\Enums\Role;

final class Permissions
{
    public function allows(StaffIdentity $staff, P $permission, ?ResourceScope $scope = null): bool
    {
        if ($staff->role === Role::Owner) {
            return true;
        }

        return match ($staff->role) {
            Role::ContentOperator => in_array($permission, [P::ContentManage, P::PublishedCatalogRead], true),
            Role::Support => match ($permission) {
                P::SupportLookup, P::SupportNote => $scope?->caseLookup === true,
                P::SubscriptionCancel => $scope?->caseLookup === true && $scope->verifiedCancellationRequest,
                default => false,
            },
            Role::Acquisition => match ($permission) {
                P::PublishedCatalogRead => true,
                P::AcquisitionManage, P::AcquisitionSummary => $scope !== null && ($scope->ownerId === $staff->id || in_array($staff->id, $scope->assignedStaffIds, true)),
                default => false,
            },
            default => false,
        };
    }
}
