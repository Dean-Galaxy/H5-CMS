<?php

namespace App\Modules\Content\Services;

use App\Modules\Content\Models\DramaRecord;

final class Availability
{
    public function query(string $region)
    {
        $query = DramaRecord::query()->where('state', 'published')->where('license_from', '<=', now())
            ->where('license_until', '>', now())->whereJsonContains('regions', $region)
            ->whereHas('episodes', fn ($q) => $q->whereNotNull('published_at'));
        if ($region === 'ZZ' || ! preg_match('/^[A-Z]{2}$/D', $region)) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public function licensed(DramaRecord $drama, string $region): bool
    {
        return $region !== 'ZZ' && preg_match('/^[A-Z]{2}$/D', $region) && in_array($region, $drama->regions, true)
            && $drama->license_from && $drama->license_until && now() >= $drama->license_from && now() < $drama->license_until;
    }
}
