<?php

namespace Tests\Feature;

use App\Models\ActualExpense;
use App\Models\Chapter;
use App\Models\ChapterType;
use App\Models\Country;
use App\Models\EstimatedExpense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\StorageFile;
use App\Models\Team;
use App\Models\TripPhase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_reports(): void
    {
        $this->get('/reportes')
            ->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_financial_reports(): void
    {
        $user = User::factory()->create();
        [$project, $tripPhase, $category] = $this->createReportContext();
        $this->createEstimatedExpense($tripPhase, $category, 500);
        $actualExpense = $this->createActualExpense($tripPhase, $category, 650);
        $this->createReceipt($actualExpense, 600);
        $this->createInvoice($tripPhase, 650);

        $this->actingAs($user)
            ->get(route('reports.index', ['project_id' => $project->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->where('filters.project_id', $project->id)
                ->loadDeferredProps('reports', fn (Assert $page) => $page
                    ->where('summary.estimated_total', 500)
                    ->where('summary.actual_total', 650)
                    ->where('summary.variance', 150)
                    ->where('summary.invoice_total', 650)
                    ->where('summary.receipt_amount', 600)
                    ->where('receiptCoverage.with_receipt', 1)
                    ->where('receiptCoverage.without_receipt', 0)
                    ->has('byProject', 1)
                    ->has('byFund', 2)
                    ->has('invoiceStatus', 1)
                )
            );
    }

    public function test_authenticated_users_can_export_report_csv(): void
    {
        $user = User::factory()->create();
        [$project, $tripPhase, $category] = $this->createReportContext();
        $this->createEstimatedExpense($tripPhase, $category, 200);
        $this->createActualExpense($tripPhase, $category, 180);

        $this->actingAs($user)
            ->get(route('reports.export', ['project_id' => $project->id]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    /**
     * @return array{0: Project, 1: TripPhase, 2: ExpenseCategory}
     */
    private function createReportContext(): array
    {
        $country = Country::query()->create([
            'name' => 'Bolivia',
            'description' => null,
        ]);
        $project = Project::query()->create([
            'country_id' => $country->id,
            'community_id' => null,
            'code' => 'BOL-REPORT',
            'name' => 'Report Project',
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
            'name' => 'Report Chapter',
            'description' => null,
        ]);
        $team = Team::query()->create([
            'chapter_id' => $chapter->id,
            'name' => 'Report Team',
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
            'volunteer_count' => 4,
            'staff_count' => 1,
            'status' => 'scheduled',
            'draft_pdf_file_id' => null,
        ]);
        $category = ExpenseCategory::query()->create([
            'name' => 'Report Lodging',
            'fund_type' => 'DR',
            'applies_service_fee' => false,
            'applies_contingency' => false,
            'service_fee_percentage' => 0,
        ]);

        return [$project, $tripPhase, $category];
    }

    private function createEstimatedExpense(TripPhase $tripPhase, ExpenseCategory $category, float $amount): void
    {
        EstimatedExpense::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'expense_category_id' => $category->id,
            'description' => 'Estimated lodging',
            'unit' => 'night',
            'initial_unit_cost' => $amount,
            'initial_quantity' => 1,
            'estimated_total' => $amount,
            'fund_type' => $category->fund_type,
        ]);
    }

    private function createActualExpense(TripPhase $tripPhase, ExpenseCategory $category, float $amount): ActualExpense
    {
        return ActualExpense::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'estimated_expense_id' => null,
            'expense_category_id' => $category->id,
            'reported_by_id' => null,
            'description' => 'Actual lodging',
            'unit' => 'night',
            'final_unit_cost' => $amount,
            'final_quantity' => 1,
            'real_total' => $amount,
            'receipt_number' => null,
            'fund_type' => $category->fund_type,
            'reported_at' => now(),
        ]);
    }

    private function createReceipt(ActualExpense $actualExpense, float $amount): void
    {
        $file = StorageFile::query()->create([
            'provider' => 'local',
            'bucket' => null,
            'object_key' => 'receipts/report.pdf',
            'original_name' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'checksum' => 'report',
            'public_url' => null,
            'uploaded_by_id' => null,
        ]);

        Receipt::query()->create([
            'actual_expense_id' => $actualExpense->id,
            'storage_file_id' => $file->id,
            'receipt_number' => 'R-1',
            'issued_on' => '2026-07-02',
            'amount' => $amount,
        ]);
    }

    private function createInvoice(TripPhase $tripPhase, float $amount): void
    {
        Invoice::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'contact_person_id' => null,
            'created_by_id' => null,
            'code' => 'REPORT-IC-INITIAL',
            'type' => 'IC',
            'stage' => 'initial',
            'status' => 'approved',
            'accounting_status' => 'pending',
            'total_dr' => $amount,
            'total_wodr' => 0,
            'grand_total' => $amount,
            'balance_conciliation' => $amount,
        ]);
    }
}
