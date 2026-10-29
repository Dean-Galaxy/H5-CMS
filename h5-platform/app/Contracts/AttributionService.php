<?php

namespace App\Contracts;

use App\Data\AttributionSnapshot;

interface AttributionService
{
    public function enabled(): bool;

    /** Null reads the current trusted server session when enabled (analytics-v1).
     * A non-null raw browser marker is untrusted and must never grant attribution.
     * Returns null when unavailable, expired, revoked, or otherwise unattributed.
     */
    public function snapshot(?string $untrustedMarker): ?AttributionSnapshot;
}
