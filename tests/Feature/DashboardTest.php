<?php

namespace Tests\Feature;

use App\Models\ActualExpense;
use App\Models\Chapter;
use App\Models\ChapterType;
use App\Models\Country;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Team;
use App\Models\TripPhase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_dashboard(): void
    {
        $this->get('/dashboard')
            ->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_dashboard_metrics(): void
    {
        $user = User::factory()->create();
        $tripPhase = $this->createDashboardContext();
        $category = ExpenseCategory::query()->create([
            'name' => 'Dashboard Lodging',
            'fund_type' => 'DR',
            'applies_service_fee' => false,
            'applies_contingency' => false,
            'service_fee_percentage' => 0,
        ]);

        ActualExpense::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'estimated_expense_id' => null,
            'expense_category_id' => $category->id,
            'reported_by_id' => $user->id,
            'description' => 'Hotel',
            'unit' => 'night',
            'final_unit_cost' => 120,
            'final_quantity' => 2,
            'real_total' => 240,
            'receipt_number' => null,
            'fund_type' => 'DR',
            'reported_at' => now(),
        ]);

        Invoice::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'contact_person_id' => null,
            'created_by_id' => $user->id,
            'code' => 'DASH-IC-INITIAL',
            'type' => 'IC',
            'stage' => 'initial',
            'status' => 'draft',
            'accounting_status' => 'pending',
            'total_dr' => 240,
            'total_wodr' => 0,
            'grand_total' => 240,
            'balance_conciliation' => 240,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->missing('metrics')
                ->has('systemRules')
                ->loadDeferredProps('dashboard', fn (Assert $page) => $page
                    ->where('metrics.open_invoices', 1)
                    ->where('metrics.expenses_without_receipts', 1)
                    ->where('financials.real_total', 240)
                    ->has('invoiceStatus', 1)
                    ->has('accountingStatus', 1)
                    ->has('upcomingTrips', 1)
                    ->has('attentionItems', 4)
                )
            );
    }

    private function createDashboardContext(): TripPhase
    {
        $country = Country::query()->create([
            'name' => 'Bolivia',
            'description' => null,
        ]);
        $project = Project::query()->create([
            'country_id' => $country->id,
            'community_id' => null,
            'code' => 'BOL-DASH',
            'name' => 'Dashboard Project',
            'started_on' => null,
            'closed_on' => null,
            'description' => null,
        ]);
        $chapterType = ChapterType::query()->create([
            'name' => 'Profesional',
            'description' => null,
        ]);
        $chapter = Chapter::query()->create([
            'chapter_type_id' => $chapterType->id,
            'university_id' => null,
            'name' => 'Dashboard Chapter',
            'description' => null,
        ]);
        $team = Team::query()->create([
            'chapter_id' => $chapter->id,
            'name' => 'Dashboard Team',
            'description' => null,
            'credit_balance' => 50,
        ]);

        return TripPhase::query()->create([
            'project_id' => $project->id,
            'team_id' => $team->id,
            'assigned_technician_id' => null,
            'phase' => 'Initial Visit',
            'starts_on' => now()->addWeek()->toDateString(),
            'ends_on' => now()->addWeeks(2)->toDateString(),
            'volunteer_count' => 4,
            'staff_count' => 1,
            'status' => 'scheduled',
            'draft_pdf_file_id' => null,
        ]);
    }
}
