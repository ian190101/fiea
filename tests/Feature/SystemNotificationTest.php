<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemNotification;
use App\Models\User;
use App\Services\SystemNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SystemNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_service_creates_notifications_for_users_with_permission(): void
    {
        $target = $this->userWithPermission('invoice_emails.manage');
        $other = User::factory()->create();

        app(SystemNotificationService::class)->notifyPermission(
            permission: 'invoice_emails.manage',
            type: 'invoice_approved',
            severity: 'info',
            title: 'Invoice aprobada',
            body: 'Lista para enviar.',
            actionUrl: '/correos-invoices',
        );

        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $target->id,
            'title' => 'Invoice aprobada',
            'read_at' => null,
        ]);
        $this->assertDatabaseMissing('system_notifications', [
            'user_id' => $other->id,
            'title' => 'Invoice aprobada',
        ]);
    }

    public function test_user_can_view_own_notifications(): void
    {
        $user = User::factory()->create();
        SystemNotification::query()->create([
            'user_id' => $user->id,
            'type' => 'backup_completed',
            'severity' => 'info',
            'title' => 'Backup generado',
            'body' => 'Backup listo.',
        ]);

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->loadDeferredProps('notifications', fn (Assert $page) => $page
                    ->has('notifications.data', 1)
                )
            );
    }

    public function test_user_can_mark_notification_as_read(): void
    {
        $user = User::factory()->create();
        $notification = SystemNotification::query()->create([
            'user_id' => $user->id,
            'type' => 'backup_completed',
            'severity' => 'info',
            'title' => 'Backup generado',
        ]);

        $this->actingAs($user)
            ->patch(route('notifications.read', $notification))
            ->assertRedirect();

        $this->assertNotNull($notification->refresh()->read_at);
    }

    public function test_user_cannot_mark_another_user_notification_as_read(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $notification = SystemNotification::query()->create([
            'user_id' => $owner->id,
            'type' => 'backup_completed',
            'severity' => 'info',
            'title' => 'Backup generado',
        ]);

        $this->actingAs($other)
            ->patch(route('notifications.read', $notification))
            ->assertNotFound();
    }

    private function userWithPermission(string $code): User
    {
        $permission = Permission::query()->create([
            'code' => $code,
            'module' => 'notifications',
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
