<?php

namespace App\Contracts;

use App\Data\AssignableStaff;
use App\Data\AssignableStaffPage;

interface AcquisitionStaffDirectory
{
    /** Owner + staff MFA required. Active acquisition staff only; limit 1..50. */
    public function candidates(?string $cursor = null, int $limit = 50): AssignableStaffPage;

    /** Re-read current eligibility before assigning; unavailable IDs return NOT_FOUND. */
    public function candidate(string $staffId): AssignableStaff;
}
