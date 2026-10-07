<?php

namespace App\Modules\Billing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BillingStore
{
    public static function id(): string
    {
        return (string) Str::uuid7();
    }

    public static function iso(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public static function json(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value;
    }

    /** All account mutations acquire this persistent row lock inside their database transaction. */
    public static function lock(string $viewerId): object
    {
        DB::table('billing_accounts')->insertOrIgnore(['viewer_id' => $viewerId]);

        return DB::table('billing_accounts')->where('viewer_id', $viewerId)->lockForUpdate()->first();
    }
}
