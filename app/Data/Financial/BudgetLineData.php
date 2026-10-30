<?php
declare(strict_types=1);
namespace App\Data\Financial;
use App\Support\Decimal;
use InvalidArgumentException;
final readonly class BudgetLineData
{
    public function __construct(
        public int $id,
        public string $bucket,
        public string $amount,
        public bool $arrangedEligible = false,
    ) {
        Decimal::valid($amount);
        if (Decimal::compare($amount, '0') < 0) {
            throw new InvalidArgumentException('El gasto debe ser no negativo.');
        }
    }
}
