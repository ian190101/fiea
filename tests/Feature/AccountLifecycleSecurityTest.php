<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccountLifecycleSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_registro_publico_no_existe(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_dashboard_requiere_autenticacion_y_permiso_explicito(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user)->get('/dashboard')->assertForbidden();
    }

    public function test_cuenta_desactivada_pierde_la_sesion_en_siguiente_peticion(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_clave_temporal_bloquea_modulos_y_permite_cambio(): void
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => true, 'password' => 'ClaveTemporal123!']);
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('profile.edit'));
        $this->put('/password', [
            'current_password' => 'ClaveTemporal123!',
            'password' => 'NuevaClaveSegura123!',
            'password_confirmation' => 'NuevaClaveSegura123!',
        ])->assertRedirect();
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_el_rol_superadmin_no_omite_permisos_explicitos(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::query()->create(['code' => 'superadmin', 'name' => 'Superadministrador']);
        $user->roles()->attach($role);
        $this->assertFalse($user->fresh()->hasPermission('dashboard.view'));
        $permission = Permission::query()->create(['code' => 'dashboard.view', 'module' => 'dashboard', 'name' => 'Ver panel']);
        $role->permissions()->attach($permission);
        $this->assertTrue($user->fresh()->hasPermission('dashboard.view'));
    }
}
