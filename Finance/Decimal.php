<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Finance;

use Box\Mod\Whmcstofossbilling\Security\SafeException;

final class Decimal
{
    public static function money(mixed $value, int $scale = 2): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new SafeException('A monetary amount was not supplied as an exact decimal string.');
        }
        $value = (string) $value;
        if (!preg_match('/\A-?\d{1,16}(?:\.\d{1,8})?\z/D', $value)) {
            throw new SafeException('A monetary amount is invalid or exceeds the supported range.');
        }
        $normalized = bcadd($value, '0', $scale);
        if (bccomp($value, $normalized, 8) !== 0) {
            throw new SafeException('A source amount has greater precision than the native destination. Native conversion refused.');
        }

        return $normalized;
    }

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, 8);
    }

    public static function round(string $value, int $scale = 2): string
    {
        $half = '0.' . str_repeat('0', $scale) . '5';

        return bccomp($value, '0', 8) < 0 ? bcsub($value, $half, $scale) : bcadd($value, $half, $scale);
    }

    public static function tax(string $subtotal, string $rate): string
    {
        return self::round(bcdiv(bcmul($subtotal, $rate, 8), '100', 8));
    }
}
