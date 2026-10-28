<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Support\DependencyNotReady;
use Illuminate\Database\Query\Builder;

/** Explicit operator pause only. Missing/invalid config and enabled-provider failures are not pauses. */
final class ProviderActivity
{
    public static function disabled(string $provider): bool
    {
        return $provider === 'paypal' && config('billing.paypal.enabled') === false;
    }

    /** Explicit callers must not mistake a pause for a successful provider observation. */
    public static function assertEnabled(string $provider): void
    {
        if (self::disabled($provider)) {
            throw new DependencyNotReady('Billing / PayPal API explicitly disabled');
        }
    }

    /** Apply before LIMIT so paused records cannot starve another provider. */
    public static function eligible(Builder $query, string $providerColumn = 'provider'): Builder
    {
        return self::disabled('paypal') ? $query->where($providerColumn, '<>', 'paypal') : $query;
    }
}
