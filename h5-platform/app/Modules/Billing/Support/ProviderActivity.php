<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Gateway\PayPal\LiveApi;
use App\Support\DependencyNotReady;
use Illuminate\Database\Query\Builder;

/** Pauses are scoped by persisted environment, independently of new-purchase selection. */
final class ProviderActivity
{
    public static function disabled(string $provider, string $environment = 'sandbox'): bool
    {
        if ($provider !== 'paypal') {
            return false;
        }
        if ($environment === 'live') {
            return config('billing.paypal_live.enabled') !== true && ! app(LiveApi::class)->offlineFake();
        }

        return config('billing.paypal.enabled') === false;
    }

    public static function assertEnabled(string $provider, string $environment = 'sandbox'): void
    {
        if (self::disabled($provider, $environment)) {
            throw new DependencyNotReady('Billing / PayPal API explicitly disabled');
        }
    }

    /** Filter before LIMIT, preserving fairness and never turning an operator pause into pending. */
    public static function eligible(Builder $query, string $providerColumn = 'provider'): Builder
    {
        $environmentColumn = str_contains($providerColumn, '.')
            ? substr($providerColumn, 0, strrpos($providerColumn, '.') + 1).'provider_environment' : 'provider_environment';
        foreach (['sandbox', 'live'] as $environment) {
            if (self::disabled('paypal', $environment)) {
                $query->where(fn ($q) => $q->where($providerColumn, '<>', 'paypal')->orWhere($environmentColumn, '<>', $environment));
            }
        }

        return $query;
    }
}
