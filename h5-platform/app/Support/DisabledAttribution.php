<?php

namespace App\Support;

use App\Contracts\AttributionService;
use App\Data\AttributionSnapshot;

final class DisabledAttribution implements AttributionService
{
    public function enabled(): bool
    {
        return false;
    }

    public function snapshot(?string $untrustedMarker): ?AttributionSnapshot
    {
        return null;
    }
}
