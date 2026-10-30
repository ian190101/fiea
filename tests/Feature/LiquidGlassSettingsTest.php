<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\LiquidGlassSettings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class LiquidGlassSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_preview_publish_and_reload_shared_profiles(): void
    {
        config()->set('superadmin.password', 'clave-exclusiva-de-prueba');
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('username', 'ian')->firstOrFail();
        $profiles = app(LiquidGlassSettings::class)->defaults();
        $this->actingAs($admin)->get(route('liquid-glass.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Superadmin/LiquidGlass')->where('profiles.panel.refraction', 0.69)->has('ranges', 16));

        $profiles['panel']['refraction'] = 1.15;
        $profiles['button']['chromAberration'] = 0.065;
        $this->put(route('liquid-glass.update'), ['profiles' => $profiles])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1.15, SystemSetting::query()->firstOrFail()->liquid_glass['panel']['refraction']);
        $this->assertTrue(AuditLog::query()->where('action', 'liquid_glass.updated')->exists());
        $this->get(route('liquid-glass.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('profiles.panel.refraction', 1.15)->where('liquidGlass.button.chromAberration', 0.065));
        $this->post(route('logout'));
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('liquidGlass.panel.refraction', 1.15));
    }

    public function test_non_superadmin_cannot_read_or_publish_even_with_the_permission(): void
    {
        $permission = Permission::query()->firstOrCreate(['code' => 'superadmin.manage'], ['name' => 'Administrar', 'module' => 'superadmin']);
        $role = Role::query()->create(['code' => 'editor-optico', 'name' => 'Editor']);
        $role->permissions()->attach($permission);
        $user = User::factory()->create();
        $user->roles()->attach($role);
        $this->actingAs($user)->getJson(route('liquid-glass.edit'))->assertForbidden();
        $this->putJson(route('liquid-glass.update'), ['profiles' => app(LiquidGlassSettings::class)->defaults()])->assertForbidden();
    }

    public function test_invalid_values_and_unknown_properties_are_rejected(): void
    {
        config()->set('superadmin.password', 'clave-exclusiva-de-prueba');
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('username', 'ian')->firstOrFail();
        $profiles = app(LiquidGlassSettings::class)->defaults();
        $profiles['panel']['blurAmount'] = 99;
        $profiles['sidebar']['injectedProperty'] = '<script>';
        $this->actingAs($admin)->putJson(route('liquid-glass.update'), ['profiles' => $profiles])
            ->assertUnprocessable()->assertJsonValidationErrors(['profiles.panel.blurAmount', 'profiles.sidebar']);
        $this->assertNull(SystemSetting::query()->firstOrFail()->liquid_glass);
    }
}
