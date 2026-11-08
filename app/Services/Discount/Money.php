<?php

namespace App\Services\Discount;

use InvalidArgumentException;

final class Money
{
    public static function minor(string $amount): int
    {
        if (PHP_INT_SIZE < 8) {
            throw new \LogicException('Exact pricing requires 64-bit PHP integers.');
        }
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/D', $amount)) {
            throw new InvalidArgumentException('Money must be a nonnegative decimal with at most two fractional digits.');
        }
        [$whole, $fraction] = array_pad(explode('.', $amount), 2, '');
        $whole = ltrim($whole, '0');
        if (strlen($whole) > 10) {
            throw new InvalidArgumentException('Money exceeds DECIMAL(12,2) range.');
        }

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $minor): string
    {
        if ($minor < 0) {
            throw new InvalidArgumentException('Money cannot be negative.');
        }

        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
