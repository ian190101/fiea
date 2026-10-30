<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Data\Financial\BudgetLineData;
use App\Data\Financial\CalculationInput;
use App\Data\Financial\ReconciliationInput;
use App\Services\Finance\IcInvoiceCalculator;
use App\Services\Finance\InvoiceTotalsFactory;
use App\Services\Finance\MatInvoiceCalculator;
use App\Services\Finance\ReconciliationService;
use Tests\TestCase;

final class FullWorkflowAcceptanceTest extends TestCase
{
    public function test_calculo_initial_final_y_conciliacion_separan_documento_de_caja(): void
    {
        $ic=new IcInvoiceCalculator(new InvoiceTotalsFactory());
        $lines=[new BudgetLineData(1,'dr','60.00',true),
            new BudgetLineData(2,'dr','440.00',false)];
        $initial=$ic->calculate(new CalculationInput('initial',$lines));
        self::assertSame('550.00',$initial->totalDr);
        self::assertSame('83.00',$initial->totalWodr);
        self::assertSame('633.00',$initial->total);
        $final=$ic->calculate(new CalculationInput('final',$lines,'80.00','0.00',$initial->total));
        self::assertSame('500.00',$final->totalDr);
        self::assertSame('83.00',$final->totalWodr);
        self::assertSame('583.00',$final->total);
        self::assertSame('-50.00',$final->balanceDue);

        $reconciliation=new ReconciliationService();
        $unpaid=$reconciliation->reconcile(new ReconciliationInput('0.00',$final->total,null,null,'0.00'));
        self::assertSame('-583.00',$unpaid->incomeLessOperating);
        self::assertNull($unpaid->operatingLessAccounting);
        $paid=$reconciliation->reconcile(new ReconciliationInput('633.00',$final->total,'580.00',null,'0.00'));
        self::assertSame('50.00',$paid->incomeLessOperating);
        self::assertSame('3.00',$paid->operatingLessAccounting);
    }

    public function test_mat_conserva_comision_y_contingencia_final_si_no_hay_override(): void
    {
        $mat=new MatInvoiceCalculator(new InvoiceTotalsFactory());
        $lines=[new BudgetLineData(1,'materials','1000.00'),
            new BudgetLineData(2,'transport','100.00')];
        $initial=$mat->calculate(new CalculationInput('initial',$lines));
        self::assertSame('1455.00',$initial->total);
        $same=$mat->calculate(new CalculationInput('final',$lines,'80.00',null,$initial->total));
        self::assertSame('1455.00',$same->total);
        $adjusted=$mat->calculate(new CalculationInput('final',$lines,'80.00','0.00',$initial->total));
        self::assertSame('1345.00',$adjusted->total);
    }
}
