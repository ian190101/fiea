<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterType;
use App\Models\Community;
use App\Models\Country;
use App\Models\EstimatedExpense;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\Team;
use App\Models\TripPhase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstimatedExpenseManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_draft_budget(): void
    {
        $this->get('/draft-budget')
            ->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_draft_budget(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/draft-budget')
            ->assertOk();
    }

    public function test_authenticated_users_can_create_estimated_expenses(): void
    {
        $user = User::factory()->create();
        $tripPhase = $this->createTripPhase();
        $category = ExpenseCategory::query()->create([
            'name' => 'Lodging',
            'description' => null,
            'fund_type' => 'DR',
            'applies_service_fee' => false,
            'applies_contingency' => false,
            'service_fee_percentage' => 0,
        ]);

        $this->actingAs($user)
            ->post(route('estimated-expenses.store'), [
                'trip_phase_id' => $tripPhase->id,
                'expense_category_id' => $category->id,
                'description' => 'Hotel rooms',
                'unit' => 'night',
                'initial_unit_cost' => 125.50,
                'initial_quantity' => 4,
                'estimated_total' => 1,
                'fund_type' => 'WODR',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('estimated_expenses', [
            'trip_phase_id' => $tripPhase->id,
            'expense_category_id' => $category->id,
            'description' => 'Hotel rooms',
            'estimated_total' => 502,
            'fund_type' => 'DR',
        ]);
    }

    public function test_estimated_expense_uses_category_fund_type(): void
    {
        $user = User::factory()->create();
        $tripPhase = $this->createTripPhase();
        $category = ExpenseCategory::query()->create([
            'name' => 'Bank Fees',
            'description' => null,
            'fund_type' => 'WODR',
            'applies_service_fee' => false,
            'applies_contingency' => false,
            'service_fee_percentage' => 0,
        ]);

        $this->actingAs($user)
            ->post(route('estimated-expenses.store'), [
                'trip_phase_id' => $tripPhase->id,
                'expense_category_id' => $category->id,
                'description' => 'Wire transfer fee',
                'unit' => 'fee',
                'initial_unit_cost' => 30,
                'initial_quantity' => 2,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('estimated_expenses', [
            'description' => 'Wire transfer fee',
            'estimated_total' => 60,
            'fund_type' => 'WODR',
        ]);
    }

    public function test_estimated_expense_rejects_negative_unit_cost(): void
    {
        $user = User::factory()->create();
        $tripPhase = $this->createTripPhase();
        $category = $this->createCategory();

        $this->actingAs($user)
            ->post(route('estimated-expenses.store'), [
                'trip_phase_id' => $tripPhase->id,
                'expense_category_id' => $category->id,
                'description' => 'Invalid cost',
                'unit' => 'unit',
                'initial_unit_cost' => -1,
                'initial_quantity' => 1,
            ])
            ->assertSessionHasErrors('initial_unit_cost');
    }

    public function test_estimated_expense_rejects_negative_quantity(): void
    {
        $user = User::factory()->create();
        $tripPhase = $this->createTripPhase();
        $category = $this->createCategory();

        $this->actingAs($user)
            ->post(route('estimated-expenses.store'), [
                'trip_phase_id' => $tripPhase->id,
                'expense_category_id' => $category->id,
                'description' => 'Invalid quantity',
                'unit' => 'unit',
                'initial_unit_cost' => 1,
                'initial_quantity' => -1,
            ])
            ->assertSessionHasErrors('initial_quantity');
    }

    public function test_authenticated_users_can_update_estimated_expenses(): void
    {
        $user = User::factory()->create();
        $tripPhase = $this->createTripPhase();
        $category = $this->createCategory();
        $expense = EstimatedExpense::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'expense_category_id' => $category->id,
            'description' => 'Original',
            'unit' => 'unit',
            'initial_unit_cost' => 10,
            'initial_quantity' => 2,
            'estimated_total' => 20,
            'fund_type' => 'DR',
        ]);

        $this->actingAs($user)
            ->patch(route('estimated-expenses.update', $expense->id), [
                'trip_phase_id' => $tripPhase->id,
                'expense_category_id' => $category->id,
                'description' => 'Updated',
                'unit' => 'person',
                'initial_unit_cost' => 15,
                'initial_quantity' => 3,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('estimated_expenses', [
            'id' => $expense->id,
            'description' => 'Updated',
            'unit' => 'person',
            'estimated_total' => 45,
        ]);
    }

    public function test_authenticated_users_can_delete_estimated_expenses(): void
    {
        $user = User::factory()->create();
        $tripPhase = $this->createTripPhase();
        $category = $this->createCategory();
        $expense = EstimatedExpense::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'expense_category_id' => $category->id,
            'description' => 'Temporary',
            'unit' => 'unit',
            'initial_unit_cost' => 10,
            'initial_quantity' => 1,
            'estimated_total' => 10,
            'fund_type' => 'DR',
        ]);

        $this->actingAs($user)
            ->delete(route('estimated-expenses.destroy', $expense->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseMissing('estimated_expenses', [
            'id' => $expense->id,
        ]);
    }

    private function createCategory(): ExpenseCategory
    {
        return ExpenseCategory::query()->create([
            'name' => 'Materials',
            'description' => null,
            'fund_type' => 'DR',
            'applies_service_fee' => false,
            'applies_contingency' => false,
            'service_fee_percentage' => 0,
        ]);
    }

    private function createTripPhase(): TripPhase
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

        return TripPhase::query()->create([
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
    }
}
