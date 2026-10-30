<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class SuperadminBootstrapAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_ian_can_log_in_and_open_protected_modules_from_first_session(): void
    {
        config()->set('superadmin.password', 'clave-de-prueba-segura-123');
        $legacy = User::factory()->create(['username' => 'admin.local', 'is_active' => true]);
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('username', 'ian')->firstOrFail();
        $this->assertTrue(Hash::check('clave-de-prueba-segura-123', $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->scope_all);
        $this->assertTrue($user->hasRole('superadmin'));
        $this->assertFalse($legacy->fresh()->is_active);
        $this->assertCount(Permission::query()->count(), $user->permissionCodes());
        $this->assertTrue(Auth::attempt(['username' => 'ian', 'password' => 'clave-de-prueba-segura-123', 'is_active' => true]));

        foreach ([
            'dashboard', 'notifications.index', 'catalogs.index', 'chapters.index',
            'contacts.index', 'projects.index', 'trip-phases.index',
            'estimated-expenses.index', 'invoices.index', 'invoice-emails.index',
            'actual-expenses.index', 'receipts.index', 'accounting.index',
            'accounting.control', 'reports.index', 'alerts.index',
            'audit-logs.index', 'operations.index', 'backups.index',
            'system-settings.edit', 'superadmin.index', 'liquid-glass.edit', 'profile.edit',
        ] as $name) {
            $response = $this->actingAs($user)->get(route($name));
            $this->assertSame(200, $response->getStatusCode(), "No se pudo abrir el módulo {$name}.");
        }

    }
}
