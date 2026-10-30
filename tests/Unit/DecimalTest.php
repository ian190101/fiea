<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Decimal as D;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    public static function roundCases(): array
    {
        return [
            ['1.005', '1.00'], ['1.015', '1.02'], ['-1.005', '-1.00'],
            ['-1.015', '-1.02'], ['0.005', '0.00'], ['0.00500001', '0.01'],
            ['999.995', '1000.00'],
        ];
    }

    #[DataProvider('roundCases')]
    public function test_redondeo_half_even(string $input, string $expected): void
    {
        self::assertSame($expected, D::money($input));
    }

    public function test_suma_decimal_sin_float(): void
    {
        self::assertSame('0.30', D::money(D::add('0.1', '0.2')));
        self::assertSame('0.01', D::money(D::sum(['0.005', '0.005'])));
    }

    #[DataProvider('invalidCases')]
    public function test_rechaza_formato_invalido(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        D::valid($value);
    }

    public static function invalidCases(): array
    {
        return [['1e3'], ['NaN'], ['1,00']];
    }

    public function test_rechaza_importe_fuera_de_rango(): void
    {
        $this->expectException(OverflowException::class);
        D::money('10000000000000.00');
    }
}
