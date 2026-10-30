<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\BillingCase;
use App\Models\BudgetLine;
use App\Models\BudgetLineVersion;
use App\Models\ChapterType;
use App\Models\Community;
use App\Models\Country;
use App\Models\ContactPerson;
use App\Models\ContactAssignment;
use App\Models\EstimatedExpense;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\StorageFile;
use App\Models\Team;
use App\Models\TripPhase;
use App\Models\User;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DraftBudgetPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_can_generate_draft_budget_pdf(): void
    {
        Storage::fake('local');

        $user = $this->authorizedUser();
        $tripPhase = $this->createTripPhaseWithEstimatedExpenses();

        $this->actingAs($user)
            ->post(route('draft-budget-pdf.store', $tripPhase->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $tripPhase->refresh();
        $this->assertNotNull($tripPhase->draft_pdf_file_id);

        $file = StorageFile::query()->findOrFail($tripPhase->draft_pdf_file_id);
        Storage::disk('local')->assertExists($file->object_key);
        $this->assertSame('application/pdf', $file->mime_type);
        $this->assertSame('local', $file->provider);
        $this->assertGreaterThan(1000, $file->size_bytes);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($file->object_key));
    }

    public function test_generating_pdf_twice_reuses_storage_record_for_same_phase(): void
    {
        Storage::fake('local');

        $user = $this->authorizedUser();
        $tripPhase = $this->createTripPhaseWithEstimatedExpenses();

        $this->actingAs($user)->post(route('draft-budget-pdf.store', $tripPhase->id));
        $firstFileId = $tripPhase->refresh()->draft_pdf_file_id;

        $this->actingAs($user)->post(route('draft-budget-pdf.store', $tripPhase->id));
        $secondFileId = $tripPhase->refresh()->draft_pdf_file_id;

        $this->assertSame($firstFileId, $secondFileId);
        $this->assertSame(1, StorageFile::query()->count());
    }

    public function test_authenticated_users_can_download_generated_pdf(): void
    {
        Storage::fake('local');

        $user = $this->authorizedUser();
        $tripPhase = $this->createTripPhaseWithEstimatedExpenses();

        $this->actingAs($user)->post(route('draft-budget-pdf.store', $tripPhase->id));

        $this->actingAs($user)
            ->get(route('draft-budget-pdf.show', $tripPhase->id))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_download_returns_not_found_when_pdf_has_not_been_generated(): void
    {
        $user = $this->authorizedUser();
        $tripPhase = $this->createTripPhaseWithEstimatedExpenses();

        $this->actingAs($user)
            ->get(route('draft-budget-pdf.show', $tripPhase->id))
            ->assertNotFound();
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->forceFill(['scope_all' => true])->save();
        $role = Role::query()->firstOrCreate(['code' => 'budgets_test'], ['name' => 'Presupuestos prueba']);
        foreach (['budgets.view', 'budgets.manage'] as $code) {
            $permission = Permission::query()->firstOrCreate(['code' => $code], [
                'module' => 'budgets', 'name' => $code,
            ]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role);
        return $user;
    }

    private function createTripPhaseWithEstimatedExpenses(): TripPhase
    {
        $country = Country::query()->create([
            'name' => 'Bolivia',
            'description' => null,
        ]);
        $community = Community::query()->create([
            'country_id' => $country->id,
            'name' => 'Santa Rosa',
            'description' => null,
        ]);
        $project = Project::query()->create([
            'country_id' => $country->id,
            'community_id' => $community->id,
            'code' => 'BOL-SR-001',
            'name' => 'Santa Rosa Water Project',
            'started_on' => null,
            'closed_on' => null,
            'description' => null,
        ]);
        $chapterType = ChapterType::query()->create([
            'name' => 'Universitario',
            'description' => null,
        ]);
        $chapter = Chapter::query()->create([
            'chapter_type_id' => $chapterType->id,
            'university_id' => null,
            'name' => 'Missouri Chapter',
            'description' => null,
        ]);
        $team = Team::query()->create([
            'chapter_id' => $chapter->id,
            'name' => 'Missouri Team',
            'description' => null,
            'credit_balance' => 0,
        ]);
        $contact = ContactPerson::query()->create([
            'full_name' => 'Contacto de prueba', 'email' => 'contacto@example.test',
            'phone' => '5551234', 'physical_address' => 'Calle de prueba 1',
        ]);
        ContactAssignment::query()->create([
            'contact_person_id' => $contact->id, 'chapter_id' => $chapter->id,
            'team_id' => $team->id, 'role' => 'Billing Contact',
            'is_billing_contact' => true, 'is_email_recipient' => true, 'is_active' => true,
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

        $lodging = ExpenseCategory::query()->create([
            'name' => 'Lodging',
            'description' => null,
            'fund_type' => 'DR',
            'applies_service_fee' => false,
            'applies_contingency' => false,
            'service_fee_percentage' => 0,
        ]);
        $fees = ExpenseCategory::query()->create([
            'name' => 'Bank Fees',
            'description' => null,
            'fund_type' => 'WODR',
            'applies_service_fee' => false,
            'applies_contingency' => false,
            'service_fee_percentage' => 0,
        ]);

        EstimatedExpense::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'expense_category_id' => $lodging->id,
            'description' => 'Hotel rooms',
            'unit' => 'night',
            'initial_unit_cost' => 125,
            'initial_quantity' => 4,
            'estimated_total' => 500,
            'fund_type' => 'DR',
        ]);
        EstimatedExpense::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'expense_category_id' => $fees->id,
            'description' => 'Wire transfer fee',
            'unit' => 'fee',
            'initial_unit_cost' => 30,
            'initial_quantity' => 1,
            'estimated_total' => 30,
            'fund_type' => 'WODR',
        ]);

        $case = BillingCase::query()->create([
            'trip_phase_id' => $tripPhase->id, 'invoice_type' => 'IC',
            'billing_mode' => 'final_only', 'metadata' => ['document_date' => '2026-07-10'],
        ]);
        $line = BudgetLine::query()->create([
            'billing_case_id' => $case->id, 'catalog_key' => 'ic.airport_pickup',
            'description_en' => 'Airport Pickup', 'unit' => 'day', 'section' => 'Transportation',
            'bucket' => 'dr', 'arranged_eligible' => true, 'sort_order' => 1,
        ]);
        $version = BudgetLineVersion::query()->create([
            'budget_line_id' => $line->id, 'stage' => 'final', 'unit_cost' => '60.0000',
        ]);
        $version->cells()->create(['spent_on' => '2026-07-01', 'quantity' => '1.0000']);

        return $tripPhase;
    }
}
