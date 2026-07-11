<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BackupManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_backups(): void
    {
        $this->get('/backups')
            ->assertRedirect('/login');
    }

    public function test_users_with_permission_can_view_backups(): void
    {
        $user = $this->userWithPermission('backups.view');

        $this->actingAs($user)
            ->get(route('backups.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Backups/Index')
                ->loadDeferredProps('backups', fn (Assert $page) => $page
                    ->has('backups')
                )
            );
    }

    public function test_users_with_permission_can_create_database_backup(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::fake('local');
        $user = $this->userWithPermission('backups.manage');

        $this->actingAs($user)
            ->post(route('backups.store'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $backup = BackupRun::query()->with('storageFile')->firstOrFail();

        $this->assertSame('completed', $backup->status);
        $this->assertSame('database', $backup->type);
        $this->assertSame($user->id, $backup->created_by_id);
        $this->assertNotNull($backup->storageFile);
        Storage::disk('local')->assertExists($backup->storageFile->object_key);

        $contents = Storage::disk('local')->get($backup->storageFile->object_key);
        $this->assertStringContainsString('FIEA database backup', $contents);
        $this->assertStringContainsString('CREATE TABLE', $contents);
    }

    public function test_completed_backup_can_be_downloaded(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::fake('local');
        $user = $this->userWithPermission('backups.manage');

        $this->actingAs($user)->post(route('backups.store'));
        $backup = BackupRun::query()->firstOrFail();

        $this->actingAs($user)
            ->get(route('backups.show', $backup))
            ->assertOk()
            ->assertHeader('content-type', 'application/sql');
    }

    public function test_backup_command_creates_database_backup(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::fake('local');

        $this->artisan('fiea:backup-database')
            ->expectsOutputToContain('Backup generado correctamente.')
            ->assertExitCode(0);

        $backup = BackupRun::query()->with('storageFile')->firstOrFail();
        Storage::disk('local')->assertExists($backup->storageFile->object_key);
    }

    private function userWithPermission(string $code): User
    {
        $permission = Permission::query()->create([
            'code' => $code,
            'module' => 'backups',
            'name' => $code,
        ]);
        $role = Role::query()->create([
            'code' => $code,
            'name' => $code,
            'description' => null,
        ]);
        $role->permissions()->attach($permission);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }
}
