<?php
declare(strict_types=1);
namespace App\Services\Finance;
use App\Data\Financial\CalculationInput;
use App\Data\Financial\InvoiceTotals;
use App\Support\Decimal as D;
final class InvoiceTotalsFactory
{
    public function make(CalculationInput $i, string $dr, string $own, string $eligible, string $rate, string $defaultFinalContingency = '0.00'): InvoiceTotals
    {
        $subtotal = D::add($dr, $own);
        $contingency = D::money($i->stage === 'initial'
            ? D::mul($subtotal, '0.10') : ($i->finalContingency ?? $defaultFinalContingency));
        $fee = D::money(D::mul($eligible, $rate));
        $bank = D::money($i->bankFee);
        $totalDr = D::money(D::add(D::money($dr), $contingency));
        $totalWodr = D::money(D::sum([D::money($own), $fee, $bank]));
        $total = D::money(D::add($totalDr, $totalWodr));
        $balance = D::money(D::sub($total, $i->stage === 'final' ? $i->initialTotal : '0'));
        return new InvoiceTotals(D::money($subtotal), D::money($eligible), $contingency, $fee,
            $bank, $totalDr, $totalWodr, $total, $balance);
    }
}
