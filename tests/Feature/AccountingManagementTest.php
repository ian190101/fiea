<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Chapter;
use App\Models\ChapterType;
use App\Models\Country;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\Team;
use App\Models\TripPhase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AccountingManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_accounting(): void
    {
        $this->get('/contabilidad')
            ->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_accounting(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/contabilidad')
            ->assertOk();
    }

    public function test_accounting_can_reconcile_without_editing_summary_when_setting_is_disabled(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createInvoice();
        SystemSetting::query()->create([
            'primary_color' => '#2563eb',
            'secondary_color' => '#0f766e',
            'accent_color' => '#f59e0b',
            'lock_final_invoice_by_default' => true,
            'accounting_can_edit_summary' => false,
        ]);

        $this->actingAs($user)
            ->patch(route('accounting.update', $invoice->id), [
                'accounting_status' => 'reconciled',
                'accounting_note' => 'Matches accounting summary.',
                'total_dr' => 1,
                'total_wodr' => 1,
                'balance_conciliation' => 1,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'accounting_status' => 'reconciled',
            'accounting_note' => 'Matches accounting summary.',
            'total_dr' => 100,
            'total_wodr' => 50,
            'grand_total' => 150,
            'balance_conciliation' => 125,
            'accounting_reviewed_by_id' => $user->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'accounting',
            'action' => 'accounting_reconciled',
            'auditable_type' => Invoice::class,
            'auditable_id' => $invoice->id,
        ]);
    }

    public function test_accounting_can_correct_summary_when_setting_is_enabled(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createInvoice();
        SystemSetting::query()->create([
            'primary_color' => '#2563eb',
            'secondary_color' => '#0f766e',
            'accent_color' => '#f59e0b',
            'lock_final_invoice_by_default' => true,
            'accounting_can_edit_summary' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('accounting.update', $invoice->id), [
                'accounting_status' => 'flagged',
                'accounting_note' => 'Adjusted from imported summary.',
                'total_dr' => 120,
                'total_wodr' => 70,
                'balance_conciliation' => 180,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'accounting_status' => 'flagged',
            'total_dr' => 120,
            'total_wodr' => 70,
            'grand_total' => 190,
            'balance_conciliation' => 180,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'accounting',
            'action' => 'accounting_summary_updated',
            'auditable_type' => Invoice::class,
            'auditable_id' => $invoice->id,
        ]);

        $this->assertNotNull(AuditLog::query()->first()?->metadata['before'] ?? null);
    }

    public function test_accounting_rejects_invalid_status(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createInvoice();

        $this->actingAs($user)
            ->patch(route('accounting.update', $invoice->id), [
                'accounting_status' => 'invalid',
                'accounting_note' => null,
            ])
            ->assertSessionHasErrors('accounting_status');
    }

    public function test_accounting_import_reconciles_without_overwriting_values_when_setting_is_disabled(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createInvoice();
        SystemSetting::query()->create([
            'primary_color' => '#2563eb',
            'secondary_color' => '#0f766e',
            'accent_color' => '#f59e0b',
            'lock_final_invoice_by_default' => true,
            'accounting_can_edit_summary' => false,
        ]);

        $file = UploadedFile::fake()->createWithContent('accounting.csv', implode("\n", [
            'code,accounting_status,total_dr,total_wodr,balance_conciliation,accounting_note',
            "{$invoice->code},reconciled,999,888,777,Imported reconciliation",
        ]));

        $this->actingAs($user)
            ->post(route('accounting-import.preview'), ['file' => $file])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('accounting-import.apply'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'accounting_status' => 'reconciled',
            'accounting_note' => 'Imported reconciliation',
            'total_dr' => 100,
            'total_wodr' => 50,
            'grand_total' => 150,
            'balance_conciliation' => 125,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'accounting',
            'action' => 'accounting_import_reconciled',
            'auditable_type' => Invoice::class,
            'auditable_id' => $invoice->id,
        ]);
    }

    public function test_accounting_import_updates_values_when_setting_is_enabled(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createInvoice();
        SystemSetting::query()->create([
            'primary_color' => '#2563eb',
            'secondary_color' => '#0f766e',
            'accent_color' => '#f59e0b',
            'lock_final_invoice_by_default' => true,
            'accounting_can_edit_summary' => true,
        ]);

        $file = UploadedFile::fake()->createWithContent('accounting.csv', implode("\n", [
            'code,accounting_status,total_dr,total_wodr,balance_conciliation,accounting_note',
            "{$invoice->code},flagged,130,20,140,Imported with corrections",
        ]));

        $this->actingAs($user)
            ->post(route('accounting-import.preview'), ['file' => $file])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('accounting.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->loadDeferredProps('accounting', fn ($page) => $page
                    ->where('importPreview.summary.total', 1)
                    ->where('importPreview.summary.valid', 1)
                    ->where('importPreview.rows.0.code', $invoice->code)
                )
            );

        $this->actingAs($user)
            ->post(route('accounting-import.apply'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'accounting_status' => 'flagged',
            'accounting_note' => 'Imported with corrections',
            'total_dr' => 130,
            'total_wodr' => 20,
            'grand_total' => 150,
            'balance_conciliation' => 140,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'accounting',
            'action' => 'accounting_import_applied_with_values',
            'auditable_type' => Invoice::class,
            'auditable_id' => $invoice->id,
        ]);
    }

    private function createInvoice(): Invoice
    {
        $suffix = (string) str()->uuid();
        $country = Country::query()->create([
            'name' => 'Bolivia '.$suffix,
            'description' => null,
        ]);
        $project = Project::query()->create([
            'country_id' => $country->id,
            'community_id' => null,
            'code' => 'BOL-'.$suffix,
            'name' => 'Santa Rosa Water Project',
            'started_on' => null,
            'closed_on' => null,
            'description' => null,
        ]);
        $chapterType = ChapterType::query()->create([
            'name' => 'Professional '.$suffix,
            'description' => null,
        ]);
        $chapter = Chapter::query()->create([
            'chapter_type_id' => $chapterType->id,
            'university_id' => null,
            'name' => 'Missouri Chapter '.$suffix,
            'description' => null,
        ]);
        $team = Team::query()->create([
            'chapter_id' => $chapter->id,
            'name' => 'Missouri Team '.$suffix,
            'description' => null,
            'credit_balance' => 25,
        ]);
        $tripPhase = TripPhase::query()->create([
            'project_id' => $project->id,
            'team_id' => $team->id,
            'assigned_technician_id' => null,
            'phase' => 'Initial Visit',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-10',
            'volunteer_count' => 10,
            'staff_count' => 2,
            'status' => 'draft',
            'draft_pdf_file_id' => null,
        ]);

        return Invoice::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'contact_person_id' => null,
            'created_by_id' => null,
            'code' => 'ACC-'.$suffix,
            'type' => 'IC',
            'stage' => 'initial',
            'status' => 'approved',
            'total_dr' => 100,
            'total_wodr' => 50,
            'grand_total' => 150,
            'balance_conciliation' => 125,
        ]);
    }
}
