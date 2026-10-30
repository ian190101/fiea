<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\Country;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserScopeEditorTest extends TestCase
{
    use RefreshDatabase;

    private function actor(array $permissions, bool $global=true): User
    {
        $role=Role::query()->create(['code'=>'scope-'.bin2hex(random_bytes(4)),'name'=>'Ámbito de prueba']);
        foreach ($permissions as $code) $role->permissions()->attach(Permission::query()
            ->firstOrCreate(['code'=>$code],['module'=>'superadmin','name'=>$code]));
        $user=User::factory()->create(['is_active'=>true,'must_change_password'=>false]);
        $user->forceFill(['scope_all'=>$global])->save(); $user->roles()->attach($role);
        return $user;
    }

    public function test_opciones_permiten_buscar_y_alcanzar_proyectos_despues_del_25(): void
    {
        $actor=$this->actor(['scope.manage']);
        $country=Country::query()->create(['name'=>'País de prueba']);
        foreach (range(1,26) as $i) Project::query()->create(['country_id'=>$country->id,'code'=>'S-'.$i,'name'=>'Proyecto '.sprintf('%02d',$i)]);
        $first=$this->actingAs($actor)->getJson(route('superadmin.scope-options','projects'))->assertOk();
        $first->assertJsonCount(25,'data');
        $this->getJson($first->json('next_page_url'))->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.name','Proyecto 26');
        $this->getJson(route('superadmin.scope-options',['kind'=>'projects','q'=>'Proyecto 26']))
            ->assertOk()->assertJsonCount(1,'data');
    }

    public function test_opciones_y_administracion_global_rechazan_alcance_parcial(): void
    {
        $partial=$this->actor(['scope.manage','superadmin.manage'],false);
        $this->actingAs($partial)->getJson(route('superadmin.scope-options','projects'))->assertForbidden();
        $this->getJson(route('superadmin.index'))->assertForbidden();
        $without=$this->actor(['superadmin.manage']);
        $this->actingAs($without)->getJson(route('superadmin.scope-options','projects'))->assertForbidden();
    }

    public function test_indice_incluye_concesiones_actuales_sin_exponer_credenciales(): void
    {
        $actor=$this->actor(['superadmin.manage','scope.manage']);
        $country=Country::query()->create(['name'=>'País de prueba']);
        $project=Project::query()->create(['country_id'=>$country->id,'code'=>'S-1','name'=>'Proyecto asignado']);
        $actor->projectGrants()->attach($project);
        $url=route('superadmin.index');
        $version=app(\App\Http\Middleware\HandleInertiaRequests::class)->version(\Illuminate\Http\Request::create($url));
        $response=$this->actingAs($actor)->get($url,['X-Inertia'=>'true','X-Inertia-Version'=>$version,
            'X-Inertia-Partial-Component'=>'Superadmin/Index','X-Inertia-Partial-Data'=>'users,canManageScope'])->assertOk();
        $response->assertJsonPath('props.canManageScope',true);
        $user=collect($response->json('props.users.data'))->firstWhere('id',$actor->id);
        self::assertSame($project->id,$user['project_grants'][0]['id']);
        self::assertArrayNotHasKey('password',$user);
        self::assertArrayNotHasKey('remember_token',$user);
        self::assertArrayNotHasKey('username_normalized',$user);
    }

    public function test_retirar_permisos_de_un_rol_personalizado_no_elimina_al_ultimo_administrador(): void
    {
        $actor=$this->actor(['superadmin.manage','scope.manage']);
        $role=$actor->roles()->firstOrFail();
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at'=>time()])
            ->patchJson(route('superadmin.roles.update',$role),['name'=>'Sin administración','permission_ids'=>[]])->assertUnprocessable();
        self::assertTrue($actor->fresh()->hasPermission('superadmin.manage'));
    }
}
