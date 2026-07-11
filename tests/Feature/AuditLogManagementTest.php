<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_audit_logs(): void
    {
        $this->get('/auditoria')
            ->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_audit_logs(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/auditoria')
            ->assertOk();
    }

    public function test_audit_logs_can_be_filtered_by_module_action_and_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => 'accounting_summary_updated',
            'module' => 'accounting',
            'auditable_type' => Invoice::class,
            'auditable_id' => 10,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'metadata' => ['after' => ['grand_total' => 100]],
        ]);
        AuditLog::query()->create([
            'user_id' => $otherUser->id,
            'action' => 'invoice_created',
            'module' => 'invoices',
            'auditable_type' => Invoice::class,
            'auditable_id' => 11,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'metadata' => null,
        ]);

        $this->actingAs($user)
            ->get(route('audit-logs.index', [
                'module' => 'accounting',
                'action' => 'accounting_summary_updated',
                'user_id' => $user->id,
            ]))
            ->assertOk()
            ->assertSee('accounting_summary_updated')
            ->assertDontSee('auditable_id&quot;:11', false);
    }

    public function test_audit_log_filter_validation_rejects_too_long_values(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('audit-logs.index', [
                'module' => str_repeat('a', 81),
            ]))
            ->assertSessionHasErrors('module');
    }
}
