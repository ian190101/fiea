<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ProductionSecurityReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_comprobacion_falla_con_rbac_vacio(): void
    {
        $this->artisan('fiea:production-check --json')->assertExitCode(1);
        $this->assertCheck('RBAC', 'failed');
    }

    public function test_comprobacion_detecta_una_clave_demo_activa_sin_exponerla(): void
    {
        $role = Role::query()->create(['code' => 'lector', 'name' => 'Lector']);
        $role->permissions()->attach(Permission::query()->create([
            'code' => 'dashboard.view', 'module' => 'dashboard', 'name' => 'Ver panel',
        ]));
        User::factory()->create(['password' => 'password', 'is_active' => true]);
        $this->assertCheck('Contraseñas de demostración', 'failed');
        $this->artisan('fiea:production-check --json')->assertExitCode(1);
    }

    public function test_comprobacion_exige_middleware_y_esquema_b02(): void
    {
        $checks = collect(app(OperationHealthService::class)->status(fresh: true)['checks']);
        self::assertSame('ok', $checks->firstWhere('name', 'Ciclo de cuenta')['status']);
        self::assertSame('ok', $checks->firstWhere('name', 'Esquema financiero B02')['status']);
        self::assertSame('ok', $checks->firstWhere('name', 'Dependencias y perímetro')['status']);
    }

    public function test_esquema_b02_incompleto_bloquea_despliegue(): void
    {
        Schema::dropIfExists('budget_cells');
        $this->assertCheck('Esquema financiero B02', 'failed');
        $this->artisan('fiea:production-check --json')->assertExitCode(1);
    }

    public function test_perimetro_inseguro_falla_en_produccion(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config()->set('app.debug', true);
        config()->set('security.trusted_proxies', []);
        config()->set('security.trusted_hosts', []);
        config()->set('session.secure', false);

        $this->assertCheck('Dependencias y perímetro', 'failed');
        $this->artisan('fiea:production-check --json')->assertExitCode(1);
    }

    private function assertCheck(string $name, string $expected): void
    {
        $checks = collect(app(OperationHealthService::class)->status(fresh: true)['checks']);
        self::assertSame($expected, $checks->firstWhere('name', $name)['status']);
    }
}
