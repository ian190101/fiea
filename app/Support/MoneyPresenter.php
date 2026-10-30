<?php
declare(strict_types=1);
namespace App\Support;
final class MoneyPresenter
{
    public static function format(string $amount): string
    {
        $value = Decimal::money(Decimal::valid($amount));
        $negative = str_starts_with($value, '-');
        $unsigned = ltrim($value, '-');
        [$whole, $fraction] = explode('.', $unsigned, 2);
        $grouped = preg_replace('/\B(?=(?:\d{3})+(?!\d))/', ',', $whole);
        return ($negative ? '-$' : '$').$grouped.'.'.$fraction;
    }
}
