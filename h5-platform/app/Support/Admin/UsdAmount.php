<?php

declare(strict_types=1);

namespace App\Support\Admin;

use InvalidArgumentException;

/** Presentation boundary only. Field limits and payment facts remain module-owned. */
final class UsdAmount
{
    public static function fromDecimal(mixed $value): int
    {
        if (! is_string($value) || ! preg_match('/\A(-?)([0-9]+)(?:\.([0-9]{1,2}))?\z/D', $value, $parts)) {
            throw new InvalidArgumentException('请输入美元金额，最多两位小数，不使用空格、逗号或科学计数法。');
        }

        $digits = ltrim($parts[2].str_pad($parts[3] ?? '', 2, '0'), '0');
        $digits = $digits === '' ? '0' : $digits;
        $negative = $parts[1] === '-' && $digits !== '0';
        $limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new InvalidArgumentException('金额过大，请检查输入。');
        }

        return (int) (($negative ? '-' : '').$digits);
    }

    public static function toDecimal(int $minor): string
    {
        $digits = (string) $minor;
        $negative = str_starts_with($digits, '-');
        $digits = str_pad($negative ? substr($digits, 1) : $digits, 3, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').substr($digits, 0, -2).'.'.substr($digits, -2);
    }

    public static function format(int $minor): string
    {
        $decimal = self::toDecimal($minor);

        return ($minor < 0 ? '-$'.substr($decimal, 1) : '$'.$decimal).' USD';
    }
}
