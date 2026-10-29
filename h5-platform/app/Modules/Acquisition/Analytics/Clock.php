<?php

namespace App\Modules\Acquisition\Analytics;

use Carbon\CarbonImmutable;

final class Clock
{
    /** Explicit text avoids Laravel/PDO's default second-only DateTime binding. */
    public static function stamp($time = null): string
    {
        return CarbonImmutable::parse($time ?? now())->utc()->format('Y-m-d H:i:s.uP');
    }
}
