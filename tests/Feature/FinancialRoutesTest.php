<?php
declare(strict_types=1);
namespace Tests\Feature;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
final class FinancialRoutesTest extends TestCase
{
    public function test_rutas_financieras_tienen_metodo_y_nombre_contractuales(): void
    {
        foreach([
            'budget-lines.store'=>'POST','budget-lines.destroy'=>'DELETE',
            'treasury.payments.store'=>'POST','treasury.expectations.update'=>'PATCH',
            'credits.apply'=>'POST','billing-cases.transition'=>'POST',
            'accounting-reviews.update'=>'PATCH','billing-cases.store'=>'POST',
            'billing-cases.settings'=>'PATCH','financial-operations.show'=>'GET',
            'accounting-reviews.edit'=>'GET','accounting.control'=>'GET',
        ] as $name=>$method) {
            $route=Route::getRoutes()->getByName($name);
            self::assertNotNull($route,$name);
            self::assertContains($method,$route->methods(),$name);
        }
    }
}
