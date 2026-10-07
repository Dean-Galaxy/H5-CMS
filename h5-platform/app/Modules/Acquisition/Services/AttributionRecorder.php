<?php

namespace App\Modules\Acquisition\Services;

use App\Contracts\AttributionService;
use App\Data\AttributionSnapshot;

final class AttributionRecorder implements AttributionService
{
    public function enabled(): bool
    {
        // D05 and the shared browser/consent entry points have not been accepted by 00.
        return false;
    }

    public function snapshot(?string $untrustedMarker): ?AttributionSnapshot
    {
        return null;
    }
}
