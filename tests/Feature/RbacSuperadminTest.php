<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacSuperadminTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_middleware_blocks_users_without_permission_when_rbac_is_seeded(): void
    {
        Permission::query()->create([
            'code' => 'settings.manage',
            'module' => 'settings',
            'name' => 'Gestionar configuracion',
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/configuracion')
            ->assertForbidden();
    }

    public function test_superadmin_role_bypasses_permission_checks(): void
    {
        Permission::query()->create([
            'code' => 'settings.manage',
            'module' => 'settings',
            'name' => 'Gestionar configuracion',
        ]);
        $role = Role::query()->create([
            'code' => 'superadmin',
            'name' => 'Superadministrador',
            'description' => null,
        ]);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        $this->actingAs($user)
            ->get('/configuracion')
            ->assertOk();
    }

    public function test_superadmin_can_view_module_with_permission(): void
    {
        [$user] = $this->createSuperadminContext();

        $this->actingAs($user)
            ->get('/superadmin')
            ->assertOk();
    }

    public function test_superadmin_can_create_user_and_assign_roles(): void
    {
        [$admin, $role] = $this->createSuperadminContext();

        $this->actingAs($admin)
            ->post(route('superadmin.users.store'), [
                'name' => 'Accounting User',
                'username' => 'accounting_user',
                'email' => 'accounting@example.com',
                'password' => 'password-123',
                'must_change_password' => true,
                'theme_preference' => 'system',
                'is_active' => true,
                'role_ids' => [$role->id],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $created = User::query()->where('username', 'accounting_user')->firstOrFail();
        $this->assertTrue($created->roles()->where('id', $role->id)->exists());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'user_created',
            'module' => 'superadmin',
            'auditable_type' => User::class,
            'auditable_id' => $created->id,
        ]);
    }

    public function test_superadmin_can_update_role_permissions(): void
    {
        [$admin, $role, $permission] = $this->createSuperadminContext();
        $extraPermission = Permission::query()->create([
            'code' => 'settings.manage',
            'module' => 'settings',
            'name' => 'Gestionar configuracion',
        ]);

        $this->actingAs($admin)
            ->patch(route('superadmin.roles.update', $role->id), [
                'name' => 'Administrativo',
                'description' => 'Rol administrativo',
                'permission_ids' => [$permission->id, $extraPermission->id],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertTrue($role->permissions()->where('code', 'settings.manage')->exists());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'role_updated',
            'module' => 'superadmin',
            'auditable_type' => Role::class,
            'auditable_id' => $role->id,
        ]);
    }

    public function test_user_cannot_deactivate_self(): void
    {
        [$admin, $role] = $this->createSuperadminContext();

        $this->actingAs($admin)
            ->patch(route('superadmin.users.update', $admin->id), [
                'name' => $admin->name,
                'username' => $admin->username,
                'email' => $admin->email,
                'password' => null,
                'must_change_password' => false,
                'theme_preference' => 'system',
                'is_active' => false,
                'role_ids' => [$role->id],
            ])
            ->assertSessionHasErrors('user');
    }

    /**
     * @return array{0: User, 1: Role, 2: Permission}
     */
    private function createSuperadminContext(): array
    {
        $permission = Permission::query()->create([
            'code' => 'superadmin.manage',
            'module' => 'superadmin',
            'name' => 'Gestionar usuarios, roles y permisos',
        ]);
        $role = Role::query()->create([
            'code' => 'administrativo',
            'name' => 'Administrativo',
            'description' => null,
        ]);
        $role->permissions()->attach($permission);
        $superadminRole = Role::query()->create([
            'code' => 'superadmin',
            'name' => 'Superadministrador',
            'description' => null,
        ]);
        $user = User::factory()->create([
            'username' => 'superadmin_user',
            'is_active' => true,
        ]);
        $user->roles()->attach($superadminRole);

        return [$user, $role, $permission];
    }
}
