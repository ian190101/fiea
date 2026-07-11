<?php

namespace Tests\Feature;

use App\Models\ActualExpense;
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

class ActualExpenseManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_actual_expenses(): void
    {
        $this->get('/gastos-reales')
            ->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_actual_expenses(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/gastos-reales')
            ->assertOk();
    }

    public function test_authenticated_users_can_create_actual_expenses(): void
    {
        $user = User::factory()->create();
        [$tripPhase, $estimatedExpense, $category] = $this->createBudgetContext();

        $this->actingAs($user)
            ->post(route('actual-expenses.store'), [
                'trip_phase_id' => $tripPhase->id,
                'estimated_expense_id' => $estimatedExpense->id,
                'expense_category_id' => $category->id,
                'description' => 'Hotel rooms final',
                'unit' => 'night',
                'final_unit_cost' => 130,
                'final_quantity' => 4,
                'receipt_number' => 'RCPT-100',
                'real_total' => 1,
                'fund_type' => 'WODR',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('actual_expenses', [
            'trip_phase_id' => $tripPhase->id,
            'estimated_expense_id' => $estimatedExpense->id,
            'expense_category_id' => $category->id,
            'description' => 'Hotel rooms final',
            'real_total' => 520,
            'fund_type' => 'DR',
            'receipt_number' => 'RCPT-100',
            'reported_by_id' => $user->id,
        ]);
    }

    public function test_actual_expense_can_be_created_without_estimated_line(): void
    {
        $user = User::factory()->create();
        [$tripPhase, , $category] = $this->createBudgetContext();

        $this->actingAs($user)
            ->post(route('actual-expenses.store'), [
                'trip_phase_id' => $tripPhase->id,
                'estimated_expense_id' => null,
                'expense_category_id' => $category->id,
                'description' => 'Unbudgeted local transport',
                'unit' => 'trip',
                'final_unit_cost' => 40,
                'final_quantity' => 2,
                'receipt_number' => null,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('actual_expenses', [
            'description' => 'Unbudgeted local transport',
            'estimated_expense_id' => null,
            'real_total' => 80,
        ]);
    }

    public function test_actual_expense_rejects_estimated_line_from_different_phase(): void
    {
        $user = User::factory()->create();
        [$firstTripPhase, , $category] = $this->createBudgetContext('BOL-SR-001');
        [, $secondEstimatedExpense] = $this->createBudgetContext('BOL-SR-002');

        $this->actingAs($user)
            ->post(route('actual-expenses.store'), [
                'trip_phase_id' => $firstTripPhase->id,
                'estimated_expense_id' => $secondEstimatedExpense->id,
                'expense_category_id' => $category->id,
                'description' => 'Invalid linked expense',
                'unit' => 'unit',
                'final_unit_cost' => 10,
                'final_quantity' => 1,
                'receipt_number' => null,
            ])
            ->assertSessionHasErrors('estimated_expense_id');
    }

    public function test_actual_expense_rejects_negative_final_cost(): void
    {
        $user = User::factory()->create();
        [$tripPhase, , $category] = $this->createBudgetContext();

        $this->actingAs($user)
            ->post(route('actual-expenses.store'), [
                'trip_phase_id' => $tripPhase->id,
                'estimated_expense_id' => null,
                'expense_category_id' => $category->id,
                'description' => 'Invalid',
                'unit' => 'unit',
                'final_unit_cost' => -1,
                'final_quantity' => 1,
                'receipt_number' => null,
            ])
            ->assertSessionHasErrors('final_unit_cost');
    }

    public function test_authenticated_users_can_update_actual_expenses(): void
    {
        $user = User::factory()->create();
        [$tripPhase, $estimatedExpense, $category] = $this->createBudgetContext();
        $actualExpense = ActualExpense::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'estimated_expense_id' => $estimatedExpense->id,
            'expense_category_id' => $category->id,
            'reported_by_id' => $user->id,
            'description' => 'Original',
            'unit' => 'night',
            'final_unit_cost' => 100,
            'final_quantity' => 2,
            'real_total' => 200,
            'receipt_number' => null,
            'fund_type' => 'DR',
            'reported_at' => now(),
        ]);

        $this->actingAs($user)
            ->patch(route('actual-expenses.update', $actualExpense->id), [
                'trip_phase_id' => $tripPhase->id,
                'estimated_expense_id' => $estimatedExpense->id,
                'expense_category_id' => $category->id,
                'description' => 'Updated',
                'unit' => 'night',
                'final_unit_cost' => 150,
                'final_quantity' => 3,
                'receipt_number' => 'RCPT-200',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('actual_expenses', [
            'id' => $actualExpense->id,
            'description' => 'Updated',
            'real_total' => 450,
            'receipt_number' => 'RCPT-200',
        ]);
    }

    public function test_authenticated_users_can_delete_actual_expenses_without_receipts(): void
    {
        $user = User::factory()->create();
        [$tripPhase, $estimatedExpense, $category] = $this->createBudgetContext();
        $actualExpense = ActualExpense::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'estimated_expense_id' => $estimatedExpense->id,
            'expense_category_id' => $category->id,
            'reported_by_id' => $user->id,
            'description' => 'Temporary',
            'unit' => 'night',
            'final_unit_cost' => 100,
            'final_quantity' => 2,
            'real_total' => 200,
            'receipt_number' => null,
            'fund_type' => 'DR',
            'reported_at' => now(),
        ]);

        $this->actingAs($user)
            ->delete(route('actual-expenses.destroy', $actualExpense->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseMissing('actual_expenses', [
            'id' => $actualExpense->id,
        ]);
    }

    /**
     * @return array{0: TripPhase, 1: EstimatedExpense, 2: ExpenseCategory}
     */
    private function createBudgetContext(string $projectCode = 'BOL-SR-001'): array
    {
        $country = Country::query()->create([
            'name' => 'Bolivia '.$projectCode,
            'description' => null,
        ]);
        $community = Community::query()->create([
            'country_id' => $country->id,
            'name' => 'Santa Rosa '.$projectCode,
            'description' => null,
        ]);
        $project = Project::query()->create([
            'country_id' => $country->id,
            'community_id' => $community->id,
            'code' => $projectCode,
            'name' => 'Santa Rosa Water Project',
            'started_on' => null,
            'closed_on' => null,
            'description' => null,
        ]);

        $chapterType = ChapterType::query()->create([
            'name' => 'Universitario '.$projectCode,
            'description' => null,
        ]);
        $chapter = Chapter::query()->create([
            'chapter_type_id' => $chapterType->id,
            'university_id' => null,
            'name' => 'Missouri Chapter '.$projectCode,
            'description' => null,
        ]);
        $team = Team::query()->create([
            'chapter_id' => $chapter->id,
            'name' => 'Missouri Team '.$projectCode,
            'description' => null,
            'credit_balance' => 0,
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

        $category = ExpenseCategory::query()->create([
            'name' => 'Lodging '.$projectCode,
            'description' => null,
            'fund_type' => 'DR',
            'applies_service_fee' => false,
            'applies_contingency' => false,
            'service_fee_percentage' => 0,
        ]);
        $estimatedExpense = EstimatedExpense::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'expense_category_id' => $category->id,
            'description' => 'Hotel rooms',
            'unit' => 'night',
            'initial_unit_cost' => 125,
            'initial_quantity' => 4,
            'estimated_total' => 500,
            'fund_type' => 'DR',
        ]);

        return [$tripPhase, $estimatedExpense, $category];
    }
}
