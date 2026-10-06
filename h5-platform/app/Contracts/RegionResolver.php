<?php

namespace App\Contracts;

use App\Data\ResolvedRegion;

interface RegionResolver
{
    /** Server-owned context only. Never derive a country from a client-supplied country field. */
    public function resolve(): ResolvedRegion;
}
