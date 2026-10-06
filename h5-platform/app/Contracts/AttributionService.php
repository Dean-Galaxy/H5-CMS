<?php

namespace App\Contracts;

use App\Data\AttributionSnapshot;

interface AttributionService
{
    public function enabled(): bool;

    /** Raw browser marker is untrusted; null means unavailable/unattributed, never verified success. */
    public function snapshot(?string $untrustedMarker): ?AttributionSnapshot;
}
