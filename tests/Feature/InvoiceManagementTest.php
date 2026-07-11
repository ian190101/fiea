<?php

namespace Tests\Feature;

use App\Models\ActualExpense;
use App\Models\Chapter;
use App\Models\ChapterType;
use App\Models\ContactPerson;
use App\Models\Country;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\StorageFile;
use App\Models\SystemSetting;
use App\Models\Team;
use App\Models\TripPhase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_invoices(): void
    {
        $this->get('/invoices')
            ->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_invoices(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/invoices')
            ->assertOk();
    }

    public function test_ic_and_mat_are_created_as_separate_invoices(): void
    {
        $user = User::factory()->create();
        [$tripPhase, $contact] = $this->createInvoiceContext();

        foreach (['IC', 'MAT'] as $type) {
            $this->actingAs($user)
                ->post(route('invoices.store'), [
                    'trip_phase_id' => $tripPhase->id,
                    'contact_person_id' => $contact->id,
                    'type' => $type,
                    'stage' => 'initial',
                ])
                ->assertSessionHasNoErrors()
                ->assertRedirect();
        }

        $this->assertDatabaseHas('invoices', [
            'trip_phase_id' => $tripPhase->id,
            'type' => 'IC',
            'stage' => 'initial',
        ]);
        $this->assertDatabaseHas('invoices', [
            'trip_phase_id' => $tripPhase->id,
            'type' => 'MAT',
            'stage' => 'initial',
        ]);
    }

    public function test_invoice_totals_are_calculated_server_side(): void
    {
        $user = User::factory()->create();
        [$tripPhase, $contact] = $this->createInvoiceContext(teamCredit: 75);
        $dr = ExpenseCategory::query()->create([
            'name' => 'Lodging',
            'fund_type' => 'DR',
            'applies_service_fee' => false,
            'applies_contingency' => false,
            'service_fee_percentage' => 0,
        ]);
        $wodr = ExpenseCategory::query()->create([
            'name' => 'Tools',
            'fund_type' => 'WODR',
            'applies_service_fee' => false,
            'applies_contingency' => false,
            'service_fee_percentage' => 0,
        ]);

        $this->createActualExpense($tripPhase, $dr, 400);
        $this->createActualExpense($tripPhase, $wodr, 150);

        $this->actingAs($user)
            ->post(route('invoices.store'), [
                'trip_phase_id' => $tripPhase->id,
                'contact_person_id' => $contact->id,
                'type' => 'IC',
                'stage' => 'initial',
                'grand_total' => 1,
                'total_dr' => 1,
                'total_wodr' => 1,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('invoices', [
            'trip_phase_id' => $tripPhase->id,
            'type' => 'IC',
            'total_dr' => 400,
            'total_wodr' => 150,
            'grand_total' => 550,
            'balance_conciliation' => 475,
        ]);
    }

    public function test_final_invoice_is_locked_when_system_setting_requires_it(): void
    {
        $user = User::factory()->create();
        [$tripPhase, $contact] = $this->createInvoiceContext();
        SystemSetting::query()->create([
            'primary_color' => '#2563eb',
            'secondary_color' => '#0f766e',
            'accent_color' => '#f59e0b',
            'lock_final_invoice_by_default' => true,
            'accounting_can_edit_summary' => false,
        ]);

        $this->actingAs($user)
            ->post(route('invoices.store'), [
                'trip_phase_id' => $tripPhase->id,
                'contact_person_id' => $contact->id,
                'type' => 'IC',
                'stage' => 'final',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertNotNull(Invoice::query()->firstOrFail()->locked_at);
    }

    public function test_locked_invoice_cannot_be_updated_or_deleted(): void
    {
        $user = User::factory()->create();
        [$tripPhase, $contact] = $this->createInvoiceContext();
        $invoice = Invoice::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'contact_person_id' => $contact->id,
            'created_by_id' => $user->id,
            'code' => 'LOCKED-IC-FINAL',
            'type' => 'IC',
            'stage' => 'final',
            'status' => 'draft',
            'total_dr' => 0,
            'total_wodr' => 0,
            'grand_total' => 0,
            'balance_conciliation' => 0,
            'locked_at' => now(),
        ]);

        $this->actingAs($user)
            ->patch(route('invoices.update', $invoice->id), [
                'contact_person_id' => null,
                'status' => 'paid',
            ])
            ->assertSessionHasErrors('invoice');

        $this->actingAs($user)
            ->delete(route('invoices.destroy', $invoice->id))
            ->assertSessionHasErrors('invoice');

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'draft',
        ]);
    }

    public function test_invoice_can_be_approved(): void
    {
        $user = User::factory()->create();
        [$tripPhase, $contact] = $this->createInvoiceContext();
        $invoice = Invoice::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'contact_person_id' => $contact->id,
            'created_by_id' => $user->id,
            'code' => 'APPROVE-IC-INITIAL',
            'type' => 'IC',
            'stage' => 'initial',
            'status' => 'draft',
            'total_dr' => 0,
            'total_wodr' => 0,
            'grand_total' => 0,
            'balance_conciliation' => 0,
        ]);

        $this->actingAs($user)
            ->post(route('invoices.approve', $invoice->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'approved',
            'approved_by_id' => $user->id,
        ]);
    }

    public function test_authenticated_users_can_generate_and_download_invoice_pdf(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        [$tripPhase, $contact] = $this->createInvoiceContext();
        $category = ExpenseCategory::query()->create([
            'name' => 'Invoice PDF Lodging',
            'fund_type' => 'DR',
            'applies_service_fee' => false,
            'applies_contingency' => false,
            'service_fee_percentage' => 0,
        ]);
        $this->createActualExpense($tripPhase, $category, 250);

        $invoice = Invoice::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'contact_person_id' => $contact->id,
            'created_by_id' => $user->id,
            'code' => 'PDF-IC-INITIAL',
            'type' => 'IC',
            'stage' => 'initial',
            'status' => 'approved',
            'total_dr' => 250,
            'total_wodr' => 0,
            'grand_total' => 250,
            'balance_conciliation' => 250,
        ]);

        $this->actingAs($user)
            ->post(route('invoices.pdf.store', $invoice->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $invoice->refresh();
        $this->assertNotNull($invoice->pdf_file_id);

        $file = StorageFile::query()->findOrFail($invoice->pdf_file_id);
        Storage::disk('local')->assertExists($file->object_key);
        $this->assertSame('application/pdf', $file->mime_type);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($file->object_key));

        $this->actingAs($user)
            ->get(route('invoices.pdf.show', $invoice->id))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    /**
     * @return array{0: TripPhase, 1: ContactPerson}
     */
    private function createInvoiceContext(float $teamCredit = 0): array
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
            'credit_balance' => $teamCredit,
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
        $contact = ContactPerson::query()->create([
            'full_name' => 'Jane Billing',
            'email' => 'jane@example.com',
            'phone' => null,
            'physical_address' => null,
        ]);

        return [$tripPhase, $contact];
    }

    private function createActualExpense(TripPhase $tripPhase, ExpenseCategory $category, float $amount): void
    {
        ActualExpense::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'estimated_expense_id' => null,
            'expense_category_id' => $category->id,
            'reported_by_id' => null,
            'description' => 'Invoice expense '.$category->name,
            'unit' => 'item',
            'final_unit_cost' => $amount,
            'final_quantity' => 1,
            'real_total' => $amount,
            'receipt_number' => null,
            'fund_type' => $category->fund_type,
            'reported_at' => now(),
        ]);
    }
}
