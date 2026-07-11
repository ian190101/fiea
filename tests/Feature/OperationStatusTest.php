<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperationStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_operation_status(): void
    {
        $this->get('/operaciones')
            ->assertRedirect('/login');
    }

    public function test_users_without_permission_cannot_view_operation_status(): void
    {
        Permission::query()->create([
            'code' => 'operations.view',
            'module' => 'operations',
            'name' => 'Ver estado operativo',
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/operaciones')
            ->assertForbidden();
    }

    public function test_users_with_permission_can_view_operation_status(): void
    {
        $permission = Permission::query()->create([
            'code' => 'operations.view',
            'module' => 'operations',
            'name' => 'Ver estado operativo',
        ]);
        $role = Role::query()->create([
            'code' => 'operaciones',
            'name' => 'Operaciones',
            'description' => null,
        ]);
        $role->permissions()->attach($permission);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        $this->actingAs($user)
            ->get(route('operations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Operations/Index')
                ->loadDeferredProps('operations', fn (Assert $page) => $page
                    ->has('health.overall')
                    ->has('health.checked_at')
                    ->has('health.checks')
                )
            );
    }
}
