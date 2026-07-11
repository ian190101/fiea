<?php

namespace Tests\Feature;

use App\Mail\InvoicePreparedMail;
use App\Models\ActualExpense;
use App\Models\Chapter;
use App\Models\ChapterType;
use App\Models\Community;
use App\Models\ContactPerson;
use App\Models\Country;
use App\Models\EmailLog;
use App\Models\EstimatedExpense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\StorageFile;
use App\Models\SystemSetting;
use App\Models\Team;
use App\Models\TripPhase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FullWorkflowAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_workflow_from_budget_to_accounting_report(): void
    {
        Storage::fake('local');
        Mail::fake();
        config([
            'filesystems.default' => 'local',
            'queue.default' => 'sync',
        ]);

        $user = User::factory()->create();
        $context = $this->createBaseContext($user);

        $this->actingAs($user)
            ->post(route('trip-phases.store'), [
                'project_id' => $context['project']->id,
                'team_id' => $context['team']->id,
                'assigned_technician_id' => $user->id,
                'phase' => 'Initial Visit',
                'starts_on' => '2026-07-01',
                'ends_on' => '2026-07-10',
                'volunteer_count' => 10,
                'staff_count' => 2,
                'status' => 'scheduled',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $tripPhase = TripPhase::query()->firstOrFail();

        $this->actingAs($user)
            ->post(route('estimated-expenses.store'), [
                'trip_phase_id' => $tripPhase->id,
                'expense_category_id' => $context['drCategory']->id,
                'description' => 'Hotel rooms',
                'unit' => 'night',
                'initial_unit_cost' => 125,
                'initial_quantity' => 4,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('estimated-expenses.store'), [
                'trip_phase_id' => $tripPhase->id,
                'expense_category_id' => $context['wodrCategory']->id,
                'description' => 'Wire transfer fee',
                'unit' => 'fee',
                'initial_unit_cost' => 30,
                'initial_quantity' => 1,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('estimated_expenses', [
            'description' => 'Hotel rooms',
            'fund_type' => 'DR',
            'estimated_total' => 500,
        ]);
        $this->assertDatabaseHas('estimated_expenses', [
            'description' => 'Wire transfer fee',
            'fund_type' => 'WODR',
            'estimated_total' => 30,
        ]);

        $this->actingAs($user)
            ->post(route('draft-budget-pdf.store', $tripPhase->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $draftFile = StorageFile::query()->findOrFail($tripPhase->refresh()->draft_pdf_file_id);
        Storage::disk('local')->assertExists($draftFile->object_key);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($draftFile->object_key));

        $estimatedExpense = EstimatedExpense::query()->where('description', 'Hotel rooms')->firstOrFail();

        $this->actingAs($user)
            ->post(route('actual-expenses.store'), [
                'trip_phase_id' => $tripPhase->id,
                'estimated_expense_id' => $estimatedExpense->id,
                'expense_category_id' => $context['drCategory']->id,
                'description' => 'Hotel rooms final',
                'unit' => 'night',
                'final_unit_cost' => 130,
                'final_quantity' => 4,
                'receipt_number' => 'REC-001',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('actual-expenses.store'), [
                'trip_phase_id' => $tripPhase->id,
                'estimated_expense_id' => null,
                'expense_category_id' => $context['wodrCategory']->id,
                'description' => 'Wire transfer fee final',
                'unit' => 'fee',
                'final_unit_cost' => 35,
                'final_quantity' => 1,
                'receipt_number' => 'REC-002',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $actualExpense = ActualExpense::query()->where('description', 'Hotel rooms final')->firstOrFail();

        $this->actingAs($user)
            ->post(route('receipts.store'), [
                'actual_expense_id' => $actualExpense->id,
                'receipt_number' => 'REC-001',
                'issued_on' => '2026-07-05',
                'amount' => 520,
                'file' => UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $receipt = Receipt::query()->with('storageFile')->firstOrFail();
        Storage::disk('local')->assertExists($receipt->storageFile->object_key);

        $this->actingAs($user)
            ->post(route('invoices.store'), [
                'trip_phase_id' => $tripPhase->id,
                'contact_person_id' => $context['contact']->id,
                'type' => 'IC',
                'stage' => 'initial',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('invoices.store'), [
                'trip_phase_id' => $tripPhase->id,
                'contact_person_id' => $context['contact']->id,
                'type' => 'MAT',
                'stage' => 'initial',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $icInvoice = Invoice::query()->where('type', 'IC')->firstOrFail();
        $matInvoice = Invoice::query()->where('type', 'MAT')->firstOrFail();

        $this->assertNotSame($icInvoice->code, $matInvoice->code);
        $this->assertStringContainsString('IC', $icInvoice->code);
        $this->assertStringContainsString('MAT', $matInvoice->code);
        $this->assertSame('520.00', $icInvoice->total_dr);
        $this->assertSame('35.00', $icInvoice->total_wodr);
        $this->assertSame('555.00', $icInvoice->grand_total);
        $this->assertSame('455.00', $icInvoice->balance_conciliation);

        $this->actingAs($user)
            ->post(route('invoices.approve', $icInvoice->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('invoices.pdf.store', $icInvoice->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $invoiceFile = StorageFile::query()->findOrFail($icInvoice->refresh()->pdf_file_id);
        Storage::disk('local')->assertExists($invoiceFile->object_key);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($invoiceFile->object_key));

        $this->actingAs($user)
            ->post(route('invoice-emails.store', $icInvoice->id), [
                'subject' => 'FIEA IC Initial Invoice',
                'body' => 'Hello, please find the invoice attached.',
                'recipients' => [
                    [
                        'contact_person_id' => $context['contact']->id,
                        'email' => 'billing@example.com',
                        'recipient_type' => 'to',
                    ],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $emailLog = EmailLog::query()->firstOrFail();

        $this->actingAs($user)
            ->post(route('invoice-emails.send', $emailLog->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        Mail::assertSent(InvoicePreparedMail::class, 1);
        $this->assertSame('sent', $emailLog->refresh()->status);
        $this->assertSame('sent', $icInvoice->refresh()->status);

        $this->actingAs($user)
            ->patch(route('accounting.update', $icInvoice->id), [
                'accounting_status' => 'reconciled',
                'accounting_note' => 'Conciliado contra resumen contable.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('invoices', [
            'id' => $icInvoice->id,
            'accounting_status' => 'reconciled',
            'accounting_reviewed_by_id' => $user->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'accounting_reconciled',
            'module' => 'accounting',
            'auditable_type' => Invoice::class,
            'auditable_id' => $icInvoice->id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('reports.export', ['project_id' => $context['project']->id]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('"Project Code","Project Name","Estimated Total","Actual Total",Variance,"Invoice Total","Balance Conciliation"', $csv);
        $this->assertStringContainsString('BOL-SR-001', $csv);
        $this->assertStringContainsString('555', $csv);
        $this->assertStringContainsString('1110', $csv);

        $this->assertDatabaseCount('invoices', 2);
        $this->assertDatabaseHas('invoices', [
            'id' => $matInvoice->id,
            'type' => 'MAT',
            'stage' => 'initial',
            'status' => 'draft',
        ]);
    }

    /**
     * @return array{project: Project, team: Team, contact: ContactPerson, drCategory: ExpenseCategory, wodrCategory: ExpenseCategory}
     */
    private function createBaseContext(User $user): array
    {
        SystemSetting::query()->create([
            'primary_color' => '#2563eb',
            'secondary_color' => '#0f766e',
            'accent_color' => '#f59e0b',
            'lock_final_invoice_by_default' => true,
            'accounting_can_edit_summary' => false,
            'updated_by_id' => $user->id,
        ]);

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
            'started_on' => '2026-06-01',
            'closed_on' => null,
            'description' => null,
        ]);
        $chapterType = ChapterType::query()->create([
            'name' => 'Professional',
            'description' => null,
        ]);
        $chapter = Chapter::query()->create([
            'chapter_type_id' => $chapterType->id,
            'university_id' => null,
            'name' => 'EWB Missouri S&T',
            'description' => null,
        ]);
        $team = Team::query()->create([
            'chapter_id' => $chapter->id,
            'name' => 'Missouri Travel Team',
            'description' => null,
            'credit_balance' => 100,
        ]);
        $contact = ContactPerson::query()->create([
            'full_name' => 'Billing Contact',
            'email' => 'billing@example.com',
            'phone' => '+1 555 0100',
            'physical_address' => '123 Engineering Way',
        ]);
        $drCategory = ExpenseCategory::query()->create([
            'name' => 'Lodging',
            'description' => null,
            'fund_type' => 'DR',
            'applies_service_fee' => false,
            'applies_contingency' => false,
            'service_fee_percentage' => 0,
        ]);
        $wodrCategory = ExpenseCategory::query()->create([
            'name' => 'Bank Fees',
            'description' => null,
            'fund_type' => 'WODR',
            'applies_service_fee' => false,
            'applies_contingency' => false,
            'service_fee_percentage' => 0,
        ]);

        return [
            'project' => $project,
            'team' => $team,
            'contact' => $contact,
            'drCategory' => $drCategory,
            'wodrCategory' => $wodrCategory,
        ];
    }
}
