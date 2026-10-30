<?php
declare(strict_types=1);
namespace App\Contracts;
use App\Data\Financial\CalculationInput;
use App\Data\Financial\InvoiceTotals;
interface InvoiceCalculatorInterface
{
    public function calculate(CalculationInput $input): InvoiceTotals;
}
