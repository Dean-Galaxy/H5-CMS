<?php

namespace App\Contracts;

use App\Data\ResourceScope;
use App\Data\StaffIdentity;
use App\Data\ViewerIdentity;
use App\Enums\Permission;

interface IdentityContext
{
    public function viewer(): ?ViewerIdentity;

    public function staff(): ?StaffIdentity;

    public function requireViewer(bool $verified = false): ViewerIdentity;

    public function requireStaff(Permission $permission, ?ResourceScope $scope = null): StaffIdentity;
}
