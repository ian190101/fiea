<?php
declare(strict_types=1);
namespace App\Data\Financial;
use App\Support\Decimal;
use InvalidArgumentException;
final readonly class CalculationInput
{
    /** @param list<BudgetLineData> $lines */
    public function __construct(
        public string $stage,
        public array $lines,
        public string $bankFee = '80.00',
        public ?string $finalContingency = null,
        public string $initialTotal = '0.00',
    ) {
        if (!in_array($stage, ['initial', 'final'], true)) {
            throw new InvalidArgumentException('Etapa inválida.');
        }
        foreach ($lines as $line) {
            if (!$line instanceof BudgetLineData) {
                throw new InvalidArgumentException('Línea de cálculo inválida.');
            }
        }
        foreach ([$bankFee, $finalContingency, $initialTotal] as $amount) {
            if ($amount === null) continue;
            Decimal::valid($amount);
            if (Decimal::compare($amount, '0') < 0) {
                throw new InvalidArgumentException('Importe base negativo.');
            }
        }
    }
}
