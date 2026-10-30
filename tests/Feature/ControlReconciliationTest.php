<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Data\Financial\ReconciliationInput;
use App\Services\Finance\ReconciliationService;
use Tests\TestCase;
final class ControlReconciliationTest extends TestCase
{
    public function test_conserva_signo_y_marca_contabilidad_desconocida(): void
    {
        $service=new ReconciliationService();
        $actual=$service->reconcile(new ReconciliationInput('2088.00','1928.00','1900.00','500.00','250.00'));
        self::assertSame('160.00',$actual->incomeLessOperating);
        self::assertSame('28.00',$actual->operatingLessAccounting);
        self::assertSame('250.00',$actual->pendingFiea);
        $missing=$service->reconcile(new ReconciliationInput('0.00','583.00',null,null,'0.00'));
        self::assertSame('-583.00',$missing->incomeLessOperating);
        self::assertNull($missing->operatingLessAccounting);
        self::assertNull($missing->pendingFiea);
    }
}
