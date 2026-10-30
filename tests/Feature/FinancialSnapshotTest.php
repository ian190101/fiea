<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Finance\InvoiceLifecycleService;
use App\Support\Decimal as D;
use ReflectionClass;
use Tests\TestCase;

final class FinancialSnapshotTest extends TestCase
{
    public function test_hash_es_independiente_del_orden_de_claves_y_conserva_cadenas(): void
    {
        $service = (new ReflectionClass(InvoiceLifecycleService::class))->newInstanceWithoutConstructor();
        $fingerprint = new \ReflectionMethod(InvoiceLifecycleService::class, 'fingerprint');
        $first = ['metadata' => ['team' => 'A', 'project' => 'B'], 'totals' => ['grand' => '0.01']];
        $second = ['totals' => ['grand' => '0.01'], 'metadata' => ['project' => 'B', 'team' => 'A']];
        self::assertSame($fingerprint->invoke($service, $first), $fingerprint->invoke($service, $second));
        self::assertSame('0.01', D::money(D::sum(['0.005', '0.005'])));
    }
}
