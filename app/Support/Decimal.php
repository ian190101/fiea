<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use OverflowException;

final class Decimal
{
    public static function valid(string $value): string
    {
        if (! preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,12})?$/D', $value)) {
            throw new InvalidArgumentException('Decimal inválido.');
        }

        return $value;
    }

    public static function add(string $a, string $b): string
    {
        return bcadd(self::valid($a), self::valid($b), 12);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub(self::valid($a), self::valid($b), 12);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul(self::valid($a), self::valid($b), 12);
    }

    /** @param iterable<string> $values */
    public static function sum(iterable $values): string
    {
        $total = '0';
        foreach ($values as $value) {
            $total = self::add($total, $value);
        }

        return bcadd($total, '0', 12);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp(self::valid($a), self::valid($b), 12);
    }

    public static function round(string $value, int $scale = 2): string
    {
        self::valid($value);
        if ($scale < 0 || $scale > 4) {
            throw new InvalidArgumentException('Escala fuera de rango.');
        }
        $negative = str_starts_with($value, '-');
        $absolute = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $absolute, 2), 2, '');
        $fraction = str_pad($fraction, $scale + 1, '0');
        $kept = substr($fraction, 0, $scale);
        $discarded = substr($fraction, $scale);
        $first = (int) $discarded[0];
        $last = (int) substr($scale === 0 ? $whole : $kept, -1);
        $moreThanHalf = $first > 5 || ($first === 5 && trim(substr($discarded, 1), '0') !== '');
        $exactHalf = $first === 5 && trim(substr($discarded, 1), '0') === '';
        $result = $whole.($scale > 0 ? '.'.$kept : '');
        if ($moreThanHalf || ($exactHalf && $last % 2 === 1)) {
            $step = $scale === 0 ? '1' : '0.'.str_repeat('0', $scale - 1).'1';
            $result = bcadd($result, $step, $scale);
        }
        $result = bcadd($result, '0', $scale);

        return $negative && bccomp($result, '0', $scale) !== 0 ? '-'.$result : $result;
    }

    public static function money(string $value): string
    {
        $rounded = self::round($value);
        if (bccomp(ltrim($rounded, '-'), '9999999999999.99', 2) > 0) {
            throw new OverflowException('El importe supera DECIMAL(15,2).');
        }

        return $rounded;
    }

    public static function quantity(string $value): string
    {
        return rtrim(rtrim(self::round($value, 4), '0'), '.');
    }
}
