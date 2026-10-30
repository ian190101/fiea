<?php
declare(strict_types=1);
namespace App\Services\Finance;
use App\Contracts\InvoiceCalculatorInterface;
use App\Data\Financial\CalculationInput;
use App\Data\Financial\InvoiceTotals;
use App\Support\Decimal as D;
use InvalidArgumentException;
final class MatInvoiceCalculator implements InvoiceCalculatorInterface
{
    public function __construct(private readonly InvoiceTotalsFactory $factory) {}
    public function calculate(CalculationInput $input): InvoiceTotals
    {
        $base = '0';
        foreach ($input->lines as $line) {
            if (!in_array($line->bucket, ['materials', 'transport'], true) || $line->arrangedEligible) {
                throw new InvalidArgumentException('Clasificación incompatible con MAT.');
            }
            $base = D::add($base, $line->amount);
        }
        return $this->factory->make($input, $base, '0', $base, '0.15', D::mul($base, '0.10'));
    }
}
