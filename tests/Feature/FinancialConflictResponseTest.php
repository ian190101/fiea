<?php
declare(strict_types=1);
namespace Tests\Feature;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
final class FinancialConflictResponseTest extends TestCase
{
    public function test_conflicto_inertia_de_mutacion_se_convierte_en_redirect_303(): void
    {
        Route::post('/test-finance-conflict',fn()=>abort(409));
        $this->withHeaders(['X-Inertia'=>'true','Referer'=>'http://localhost/dashboard'])
            ->post('/test-finance-conflict')->assertStatus(303)
            ->assertSessionHasErrors('version');
    }

    public function test_conflicto_get_permanece_409(): void
    {
        Route::get('/test-finance-conflict-get',fn()=>abort(409));
        $this->withHeaders(['X-Inertia'=>'true'])->get('/test-finance-conflict-get')->assertStatus(409);
    }
}
