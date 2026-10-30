<?php
declare(strict_types=1);
namespace App\Support;

final class CsvCell
{
    public static function text(?string $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], [' ', ' ', ''], $value ?? '');
        return preg_match('/\A[\s]*[=+\-@\t]/u', $value) ? "'".$value : $value;
    }
}
