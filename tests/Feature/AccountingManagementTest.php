<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Http\Requests\UpdateAccountingReviewRequest;
use App\Models\Role;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class AccountingManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitado_no_accede_a_contabilidad(): void
    {
        $this->get('/contabilidad')->assertRedirect('/login');
    }

    public function test_rbac_exige_permiso_contable(): void
    {
        $user=User::query()->create(['name'=>'Contador','username'=>'contador-prueba',
            'password'=>'ClaveTemporal123!','is_active'=>true,'must_change_password'=>false]);
        $user->forceFill(['scope_all'=>true])->save();
        $this->actingAs($user)->get('/contabilidad')->assertForbidden();
        $user->roles()->attach($this->accountingRole());
        $user->unsetRelation('roles');
        $this->actingAs($user)->get('/contabilidad')->assertOk();
    }

    public function test_revision_no_admite_importes_del_documento(): void
    {
        $rules=(new UpdateAccountingReviewRequest())->rules();
        $errors=Validator::make([
            'version'=>1,'accounting_total'=>'1900.00','note'=>'Revisado',
            'total_dr'=>'0.01','grand_total'=>'0.01','difference'=>'0.01',
        ],$rules)->errors();
        self::assertTrue($errors->has('total_dr'));
        self::assertTrue($errors->has('grand_total'));
        self::assertTrue($errors->has('difference'));
        self::assertTrue(Validator::make([
            'version'=>1,'accounting_total'=>'-0.01','note'=>'Diferencia firmada',
        ],$rules)->passes());
    }

    public function test_importador_de_control_rechaza_csv_como_xlsx(): void
    {
        $user=User::query()->create(['name'=>'Importador','username'=>'contador-import',
            'password'=>'ClaveTemporal123!','is_active'=>true,'must_change_password'=>false]);
        $user->forceFill(['scope_all'=>true])->save();
        $user->roles()->attach($this->accountingRole());
        $this->actingAs($user)->post(route('accounting-import.preview'),[
            'sheet'=>'2026','file'=>UploadedFile::fake()->create('legacy.csv',1,'text/csv'),
        ])->assertSessionHasErrors('file');
    }
    private function accountingRole(): Role
    {
        $role=Role::query()->firstOrCreate(['code'=>'contabilidad_prueba'],['name'=>'Contabilidad prueba']);
        foreach(['accounting.view','accounting.manage'] as $code) {
            $permission=Permission::query()->firstOrCreate(
                ['code'=>$code],['module'=>'accounting','name'=>$code]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        return $role;
    }
}
