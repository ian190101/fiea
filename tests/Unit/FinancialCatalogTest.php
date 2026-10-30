<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Finance\BudgetCatalog;
use PHPUnit\Framework\TestCase;

final class FinancialCatalogTest extends TestCase
{
    public function test_clasificacion_por_clave_independiente_del_nombre(): void
    {
        $catalog = new BudgetCatalog();
        $rows = collect($catalog->forType('IC'))->keyBy('catalog_key');
        self::assertTrue($rows['ic.airport_pickup']['arranged_eligible']);
        foreach (['ic.hotel', 'ic.food_3', 'ic.fuel', 'ic.toll', 'ic.nissan'] as $key) {
            self::assertFalse($rows[$key]['arranged_eligible']);
        }
        self::assertSame('own', $rows['ic.engineering']['bucket']);
        $renamed = $rows['ic.airport_pickup'];
        $renamed['description_en'] = 'Otro nombre';
        self::assertSame('dr', $renamed['bucket']);
        self::assertTrue($renamed['arranged_eligible']);
        self::assertTrue($catalog->customMiscellaneous('Otro servicio')['arranged_eligible']);
        self::assertCount(2, $catalog->forType('MAT'));
    }
}
