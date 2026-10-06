<?php

namespace App\Contracts;

use App\Data\Membership;

interface MembershipReader
{
    /** Trusted internal viewer ID; only the provider computes paid access at the UTC instant. */
    public function membership(string $viewerId, \DateTimeImmutable $at): Membership;
}
