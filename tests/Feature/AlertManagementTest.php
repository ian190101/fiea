<?php

namespace Tests\Feature;

use App\Models\ActualExpense;
use App\Models\Chapter;
use App\Models\ChapterType;
use App\Models\Country;
use App\Models\EmailLog;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Team;
use App\Models\TripPhase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AlertManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_alerts(): void
    {
        $this->get('/alertas')
            ->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_operational_alerts(): void
    {
        $user = User::factory()->create();

        $this->createAlertContext($user);

        $this->actingAs($user)
            ->get('/alertas')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Alerts/Index')
                ->has('types', 5)
                ->has('severities', 3)
                ->loadDeferredProps('alerts', fn (Assert $page) => $page
                    ->where('summary.total', 6)
                    ->where('summary.critical', 3)
                    ->where('summary.warning', 2)
                    ->where('summary.info', 1)
                    ->has('alerts', 6)
                )
            );
    }

    public function test_alerts_can_be_filtered_by_type_and_severity(): void
    {
        $user = User::factory()->create();

        $this->createAlertContext($user);

        $this->actingAs($user)
            ->get('/alertas?type=email&severity=critical')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Alerts/Index')
                ->where('filters.type', 'email')
                ->where('filters.severity', 'critical')
                ->loadDeferredProps('alerts', fn (Assert $page) => $page
                    ->where('summary.total', 1)
                    ->where('alerts.0.type', 'email')
                    ->where('alerts.0.severity', 'critical')
                )
            );
    }

    private function createAlertContext(User $user): void
    {
        $country = Country::query()->create([
            'name' => 'Bolivia',
            'description' => null,
        ]);
        $project = Project::query()->create([
            'country_id' => $country->id,
            'community_id' => null,
            'code' => 'BOL-ALT',
            'name' => 'Alert Project',
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
            'name' => 'Alert Chapter',
            'description' => null,
        ]);
        $team = Team::query()->create([
            'chapter_id' => $chapter->id,
            'name' => 'Alert Team',
            'description' => null,
            'credit_balance' => 0,
        ]);
        $tripPhase = TripPhase::query()->create([
            'project_id' => $project->id,
            'team_id' => $team->id,
            'assigned_technician_id' => null,
            'phase' => 'Initial Visit',
            'starts_on' => now()->addDays(7)->toDateString(),
            'ends_on' => now()->addDays(10)->toDateString(),
            'volunteer_count' => 4,
            'staff_count' => 1,
            'status' => 'draft',
            'draft_pdf_file_id' => null,
        ]);
        $category = ExpenseCategory::query()->create([
            'name' => 'Alert Lodging',
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
            'description' => 'Hotel without receipt',
            'unit' => 'night',
            'final_unit_cost' => 120,
            'final_quantity' => 2,
            'real_total' => 240,
            'receipt_number' => null,
            'fund_type' => 'DR',
            'reported_at' => now(),
        ]);

        $invoice = Invoice::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'contact_person_id' => null,
            'created_by_id' => $user->id,
            'code' => 'ALT-IC-INITIAL',
            'type' => 'IC',
            'stage' => 'initial',
            'status' => 'draft',
            'accounting_status' => 'flagged',
            'total_dr' => 240,
            'total_wodr' => 0,
            'grand_total' => 240,
            'balance_conciliation' => 240,
            'accounting_note' => 'Diferencia pendiente de revisar.',
            'pdf_file_id' => null,
        ]);

        EmailLog::query()->create([
            'invoice_id' => $invoice->id,
            'subject' => 'Invoice failed',
            'body' => 'Body',
            'status' => 'failed',
            'error_message' => 'SMTP rejected message.',
            'sent_at' => null,
        ]);
    }
}
