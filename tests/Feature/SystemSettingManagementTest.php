<?php

namespace Tests\Feature;

use App\Models\StorageFile;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ExplicitAccess;
use Tests\TestCase;

class SystemSettingManagementTest extends TestCase
{
    use ExplicitAccess;
    use RefreshDatabase;

    public function test_guests_are_redirected_from_system_settings(): void
    {
        $this->get('/configuracion')
            ->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_system_settings(): void
    {
        $user = $this->authorizedUser(['settings.manage']);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->get('/configuracion')
            ->assertOk();
    }

    public function test_authenticated_users_can_update_branding(): void
    {
        $user = $this->authorizedUser(['settings.manage']);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('system-settings.update'), [
                'primary_color' => '#111111',
                'secondary_color' => '#222222',
                'accent_color' => '#333333',

            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('system_settings', [
            'id' => 1,
            'primary_color' => '#111111',
            'secondary_color' => '#222222',
            'accent_color' => '#333333',

            'updated_by_id' => $user->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'system_settings_updated',
            'module' => 'settings',
            'auditable_type' => SystemSetting::class,
            'auditable_id' => 1,
        ]);
    }

    public function test_system_settings_reject_invalid_hex_color(): void
    {
        $user = $this->authorizedUser(['settings.manage']);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('system-settings.update'), [
                'primary_color' => 'blue',
                'secondary_color' => '#222222',
                'accent_color' => '#333333',

            ])
            ->assertSessionHasErrors('primary_color');
    }

    public function test_system_settings_can_upload_logo(): void
    {
        Storage::fake('local');
        $user = $this->authorizedUser(['settings.manage']);
        $logo = UploadedFile::fake()->createWithContent('logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('system-settings.update'), [
                'primary_color' => '#2563eb',
                'secondary_color' => '#0f766e',
                'accent_color' => '#f59e0b',

                'logo' => $logo,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $storageFile = StorageFile::query()->firstOrFail();
        Storage::disk('local')->assertExists($storageFile->object_key);

        $this->assertDatabaseHas('system_settings', [
            'id' => 1,
            'logo_file_id' => $storageFile->id,
        ]);
    }

    public function test_system_settings_upload_logo_to_r2_when_cloudflare_is_configured(): void
    {
        config([
            'filesystems.default' => 'r2',
            'filesystems.disks.r2.key' => 'test-key',
            'filesystems.disks.r2.secret' => 'test-secret',
            'filesystems.disks.r2.bucket' => 'fiea-test',
            'filesystems.disks.r2.endpoint' => 'https://example.r2.cloudflarestorage.com',
            'filesystems.disks.r2.url' => 'https://files.example.com',
        ]);
        Storage::fake('r2');

        $user = $this->authorizedUser(['settings.manage']);
        $logo = UploadedFile::fake()->createWithContent('logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('system-settings.update'), [
                'primary_color' => '#2563eb',
                'secondary_color' => '#0f766e',
                'accent_color' => '#f59e0b',

                'logo' => $logo,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $storageFile = StorageFile::query()->firstOrFail();
        Storage::disk('r2')->assertExists($storageFile->object_key);

        $this->assertSame('cloudflare_r2', $storageFile->provider);
        $this->assertSame('fiea-test', $storageFile->bucket);
        $this->assertNull($storageFile->public_url);
    }
}
