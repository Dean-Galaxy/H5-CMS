<?php

namespace App\Data;

/** Must be populated from persisted provider-owned records, never request ownership fields. */
final readonly class ResourceScope
{
    public function __construct(public ?string $ownerId = null, public array $assignedStaffIds = [], public bool $caseLookup = false, public bool $verifiedCancellationRequest = false) {}
}
