<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProductionReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_check_command_outputs_readable_status(): void
    {
        $this->artisan('fiea:production-check')
            ->expectsOutputToContain('Revision operativa FIEA')
            ->expectsOutputToContain('Estado general:')
            ->assertExitCode(0);
    }

    public function test_production_check_command_outputs_json_status(): void
    {
        $exitCode = Artisan::call('fiea:production-check', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('overall', $payload);
        $this->assertArrayHasKey('checks', $payload);
    }

    public function test_rbac_seed_check_fails_without_permissions(): void
    {
        $this->artisan('fiea:rbac-seed-check')
            ->expectsOutput('RBAC invalido: no existen permisos sembrados.')
            ->assertFailed();
    }

    public function test_rbac_seed_check_passes_when_superadmin_has_all_permissions(): void
    {
        $permission = Permission::query()->create([
            'code' => 'superadmin.manage',
            'module' => 'superadmin',
            'name' => 'Gestionar usuarios, roles y permisos',
        ]);
        $role = Role::query()->create([
            'code' => 'superadmin',
            'name' => 'Superadministrador',
        ]);
        $role->permissions()->sync([$permission->id]);

        $this->artisan('fiea:rbac-seed-check')
            ->expectsOutput('RBAC OK: 1 permisos y superadmin completo.')
            ->assertSuccessful();
    }
}
