<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Data\Financial\BudgetLineData as L;
use App\Data\Financial\CalculationInput as I;
use App\Services\Finance\IcInvoiceCalculator;
use App\Services\Finance\InvoiceTotalsFactory;
use App\Services\Finance\MatInvoiceCalculator;
use PHPUnit\Framework\TestCase;

final class InvoiceCalculatorTest extends TestCase
{
    public function test_ie_25_019_conserva_credito_documental(): void
    {
        $lines = [new L(1, 'dr', '60', true), new L(2, 'dr', '260'),
            new L(3, 'dr', '160'), new L(4, 'dr', '20')];
        $calculator = new IcInvoiceCalculator(new InvoiceTotalsFactory());
        $initial = $calculator->calculate(new I('initial', $lines));
        self::assertSame(['500.00','60.00','50.00','3.00','80.00','550.00','83.00','633.00'],
            [$initial->subtotal,$initial->eligibleBase,$initial->contingency,$initial->serviceFee,
             $initial->bankFee,$initial->totalDr,$initial->totalWodr,$initial->total]);
        $final = $calculator->calculate(new I('final', $lines, initialTotal: $initial->total));
        self::assertSame(['500.00','83.00','583.00','-50.00'],
            [$final->totalDr,$final->totalWodr,$final->total,$final->balanceDue]);
        self::assertSame('583.00', $calculator->calculate(new I('final', $lines))->balanceDue);
    }

    public function test_mat_y_costos_propios_aplican_reglas_por_tipo(): void
    {
        $factory = new InvoiceTotalsFactory();
        $mat = new MatInvoiceCalculator($factory);
        $lines = [new L(1, 'materials', '1000'), new L(2, 'transport', '100')];
        $initial = $mat->calculate(new I('initial', $lines));
        self::assertSame(['1210.00', '245.00', '1455.00'], [$initial->totalDr, $initial->totalWodr, $initial->total]);
        $final = $mat->calculate(new I('final', $lines, finalContingency: '0.00', initialTotal: $initial->total));
        self::assertSame(['1100.00', '245.00', '1345.00', '-110.00'],
            [$final->totalDr, $final->totalWodr, $final->total, $final->balanceDue]);
        self::assertSame('1455.00', $mat->calculate(new I('final', $lines))->total);
        $ic = new IcInvoiceCalculator($factory);
        $own = $ic->calculate(new I('initial', [new L(1, 'own', '100'), new L(2, 'dr', '60', true)]));
        self::assertSame(['76.00', '183.00', '259.00'], [$own->totalDr, $own->totalWodr, $own->total]);
        self::assertSame('0.00', $ic->calculate(new I('final', [new L(1, 'dr', '0')], bankFee: '0.00'))->bankFee);
    }
}
