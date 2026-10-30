<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Http\Requests\StoreInvoiceRequest;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Permission;
use App\Models\User;
use App\Services\InvoicePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class InvoiceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitado_no_accede_a_invoices(): void
    {
        $this->get('/invoices')->assertRedirect('/login');
    }

    public function test_rbac_cerrado_y_usuario_autorizado_puede_leer(): void
    {
        $user=User::query()->create(['name'=>'Lector','username'=>'lector-invoices',
            'password'=>'ClaveTemporal123!','is_active'=>true,'must_change_password'=>false]);
        $user->forceFill(['scope_all'=>true])->save();
        $this->actingAs($user)->get('/invoices')->assertForbidden();
        $role=Role::query()->create(['code'=>'invoices_reader','name'=>'Lector de facturas']);
        $role->permissions()->attach(Permission::query()->create([
            'code'=>'invoices.view','module'=>'invoices','name'=>'Ver facturas']));
        $user->roles()->attach($role);
        $user->unsetRelation('roles');
        $this->actingAs($user)->get('/invoices')->assertOk();
    }

    public function test_mutadores_directos_se_eliminaron_y_totales_cliente_estan_prohibidos(): void
    {
        self::assertNull(Route::getRoutes()->getByName('invoices.update'));
        self::assertNull(Route::getRoutes()->getByName('invoices.destroy'));
        $rules=(new StoreInvoiceRequest())->rules();
        $errors=Validator::make([
            'billing_case_id'=>1,'stage'=>'initial','version'=>1,
            'idempotency_key'=>'123e4567-e89b-12d3-a456-426614174070',
            'grand_total'=>'0.01','total_dr'=>'0.01',
        ],$rules)->errors();
        self::assertTrue($errors->has('grand_total'));
        self::assertTrue($errors->has('total_dr'));
    }

    public function test_pdf_legado_sin_snapshot_no_se_regenera(): void
    {
        $invoice=new Invoice();
        try {
            app(InvoicePdfService::class)->generate($invoice);
            self::fail('No debe reinterpretarse un documento legado sin snapshot.');
        } catch(HttpException $exception) {
            self::assertSame(422,$exception->getStatusCode());
        }
    }
}
