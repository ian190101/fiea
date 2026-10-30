<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Contracts\DatabaseSnapshotWriter;
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
        $this->fakeSnapshotWriter();
        $user = $this->userWithPermission('backups.manage');

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
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
        $this->assertStringStartsWith('FIEA-BACKUP-1', $contents);
        $this->assertStringNotContainsString('CREATE TABLE', $contents);
    }

    public function test_completed_backup_can_be_downloaded(): void
    {
        $this->fakeSnapshotWriter();
        $user = $this->userWithPermission('backups.manage');

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->post(route('backups.store'));
        $backup = BackupRun::query()->firstOrFail();

        $this->actingAs($user)
            ->get(route('backups.show', $backup))
            ->assertOk()
            ->assertHeader('content-type', 'application/octet-stream');
    }

    public function test_backup_command_creates_database_backup(): void
    {
        $this->fakeSnapshotWriter();

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
        if ($code === 'backups.manage') {
            $view = Permission::query()->create(['code' => 'backups.view',
                'module' => 'backups', 'name' => 'backups.view']);
            $role->permissions()->attach($view);
        }
        $user = User::factory()->create();
        $user->forceFill(['scope_all' => true])->save();
        $user->roles()->attach($role);

        return $user;
    }

    private function fakeSnapshotWriter(): void
    {
        Storage::fake('local');
        config()->set('filesystems.default', 'local');
        config()->set('services.backup.encryption_key', base64_encode(random_bytes(32)));
        $this->app->instance(DatabaseSnapshotWriter::class, new class implements DatabaseSnapshotWriter {
            public function write(): string
            {
                $path = tempnam(sys_get_temp_dir(), 'fiea-backup-');
                file_put_contents($path, "CREATE TABLE verificada(id INTEGER);\n");
                return $path;
            }
        });
    }
}
