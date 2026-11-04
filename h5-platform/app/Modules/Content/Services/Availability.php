<?php

namespace App\Modules\Content\Services;

use App\Modules\Content\Models\DramaRecord;

final class Availability
{
    public static function worldwide(): bool
    {
        return config('h5.content_worldwide_self_produced') === true;
    }

    public static function acceptsRegion(string $region): bool
    {
        return preg_match('/^[A-Z]{2}$/D', $region) === 1 && (self::worldwide() || $region !== 'ZZ');
    }

    public function query(string $region)
    {
        $query = DramaRecord::query()->where('state', 'published')
            ->whereHas('episodes', fn ($q) => $q->whereNotNull('published_at'));

        if (! self::acceptsRegion($region)) {
            return $query->whereRaw('1 = 0');
        }

        if (! self::worldwide()) {
            $query->where('license_from', '<=', now())
                ->where('license_until', '>', now())->whereJsonContains('regions', $region);
        }

        return $query;
    }

    public function licensed(DramaRecord $drama, string $region): bool
    {
        return self::acceptsRegion($region) && (self::worldwide() ||
            (in_array($region, $drama->regions, true) && $drama->license_from && $drama->license_until
                && now() >= $drama->license_from && now() < $drama->license_until));
    }
}
