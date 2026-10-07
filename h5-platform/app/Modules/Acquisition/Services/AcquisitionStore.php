<?php

namespace App\Modules\Acquisition\Services;

use Illuminate\Support\Str;

final class AcquisitionStore
{
    public static function id(): string
    {
        return (string) Str::uuid7();
    }

    public static function json(mixed $value): ?array
    {
        return is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value;
    }
}
