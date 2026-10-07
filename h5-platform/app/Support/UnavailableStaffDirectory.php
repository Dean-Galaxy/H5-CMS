<?php

namespace App\Support;

use App\Contracts\AcquisitionStaffDirectory;
use App\Data\AssignableStaff;
use App\Data\AssignableStaffPage;

final class UnavailableStaffDirectory implements AcquisitionStaffDirectory
{
    public function candidates(?string $cursor = null, int $limit = 50): AssignableStaffPage
    {
        throw new DependencyNotReady('Identity / Acquisition staff directory');
    }

    public function candidate(string $staffId): AssignableStaff
    {
        throw new DependencyNotReady('Identity / Acquisition staff directory');
    }
}
