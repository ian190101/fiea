<?php
declare(strict_types=1);
namespace App\Services\Finance;
use App\Contracts\InvoiceCalculatorInterface;
use App\Data\Financial\CalculationInput;
use App\Data\Financial\InvoiceTotals;
use App\Support\Decimal as D;
use InvalidArgumentException;
final class IcInvoiceCalculator implements InvoiceCalculatorInterface
{
    public function __construct(private readonly InvoiceTotalsFactory $factory) {}
    public function calculate(CalculationInput $input): InvoiceTotals
    {
        $dr = $own = $eligible = '0';
        foreach ($input->lines as $line) {
            if ($line->bucket === 'dr') {
                $dr = D::add($dr, $line->amount);
                if ($line->arrangedEligible) $eligible = D::add($eligible, $line->amount);
            } elseif ($line->bucket === 'own' && !$line->arrangedEligible) {
                $own = D::add($own, $line->amount);
            } else {
                throw new InvalidArgumentException('Clasificación incompatible con IC.');
            }
        }
        return $this->factory->make($input, $dr, $own, $eligible, '0.05');
    }
}
