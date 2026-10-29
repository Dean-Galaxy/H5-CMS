<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateway\PayPal;

use InvalidArgumentException;

/** Boundary conversion only. Ledger amounts remain integer USD minor units. */
final class PayPalAmount
{
    public const MAX_MINOR = 99999999;

    public static function minor(mixed $decimal, mixed $currency): int
    {
        if ($currency !== 'USD' || ! is_string($decimal)
            || preg_match('/\A(0|[1-9][0-9]{0,5})(?:\.([0-9]{1,2}))?\z/D', $decimal, $parts) !== 1) {
            throw new InvalidArgumentException('PAYPAL_AMOUNT_INVALID');
        }

        return ((int) $parts[1]) * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    public static function decimal(int $minor): string
    {
        if ($minor < 0 || $minor > self::MAX_MINOR) {
            throw new InvalidArgumentException('PAYPAL_AMOUNT_INVALID');
        }

        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
