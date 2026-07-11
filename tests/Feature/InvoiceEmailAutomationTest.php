<?php

namespace Tests\Feature;

use App\Mail\InvoicePreparedMail;
use App\Models\Chapter;
use App\Models\ChapterType;
use App\Models\ContactPerson;
use App\Models\Country;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceRecipient;
use App\Models\Project;
use App\Models\Team;
use App\Models\TripPhase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceEmailAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_prepares_automated_email_for_approved_invoice(): void
    {
        [$invoice, $contact] = $this->createInvoiceContext();
        InvoiceRecipient::query()->create([
            'invoice_id' => $invoice->id,
            'contact_person_id' => $contact->id,
            'email' => 'billing@example.com',
            'recipient_type' => 'to',
        ]);

        $this->artisan('fiea:email-automation --prepare')
            ->assertSuccessful();

        $this->assertDatabaseHas('email_logs', [
            'invoice_id' => $invoice->id,
            'status' => 'pending',
            'source' => 'automation',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'invoice_email_auto_prepared',
            'module' => 'emails',
            'auditable_type' => EmailLog::class,
        ]);
    }

    public function test_command_sends_automated_pending_email(): void
    {
        Mail::fake();
        Storage::fake('local');

        [$invoice, $contact] = $this->createInvoiceContext();
        InvoiceRecipient::query()->create([
            'invoice_id' => $invoice->id,
            'contact_person_id' => $contact->id,
            'email' => 'billing@example.com',
            'recipient_type' => 'to',
        ]);
        $emailLog = EmailLog::query()->create([
            'invoice_id' => $invoice->id,
            'subject' => 'Automated invoice',
            'body' => 'Hello.',
            'status' => 'pending',
            'source' => 'automation',
        ]);

        $this->artisan('fiea:email-automation --send-pending')
            ->assertSuccessful();

        Mail::assertSent(InvoicePreparedMail::class, 1);

        $this->assertSame('sent', $emailLog->refresh()->status);
        $this->assertNotNull($emailLog->sent_at);
        $this->assertNotNull($emailLog->last_attempted_at);
        $this->assertNotNull($invoice->refresh()->pdf_file_id);
    }

    public function test_command_retries_due_failed_email(): void
    {
        Mail::fake();
        Storage::fake('local');

        [$invoice, $contact] = $this->createInvoiceContext();
        InvoiceRecipient::query()->create([
            'invoice_id' => $invoice->id,
            'contact_person_id' => $contact->id,
            'email' => 'billing@example.com',
            'recipient_type' => 'to',
        ]);
        $emailLog = EmailLog::query()->create([
            'invoice_id' => $invoice->id,
            'subject' => 'Retry invoice',
            'body' => 'Hello.',
            'status' => 'failed',
            'source' => 'automation',
            'retry_count' => 1,
            'next_retry_at' => now()->subMinute(),
            'error_message' => 'Temporary failure.',
        ]);

        $this->artisan('fiea:email-automation --retry-failed')
            ->assertSuccessful();

        Mail::assertSent(InvoicePreparedMail::class, 1);

        $this->assertSame('sent', $emailLog->refresh()->status);
        $this->assertNull($emailLog->next_retry_at);
    }

    /**
     * @return array{0: Invoice, 1: ContactPerson}
     */
    private function createInvoiceContext(): array
    {
        $suffix = (string) str()->uuid();
        $country = Country::query()->create([
            'name' => 'Bolivia '.$suffix,
            'description' => null,
        ]);
        $project = Project::query()->create([
            'country_id' => $country->id,
            'community_id' => null,
            'code' => 'BOL-AUTO-'.$suffix,
            'name' => 'Automation Project',
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
            'name' => 'Automation Chapter '.$suffix,
            'description' => null,
        ]);
        $team = Team::query()->create([
            'chapter_id' => $chapter->id,
            'name' => 'Automation Team '.$suffix,
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
            'status' => 'scheduled',
            'draft_pdf_file_id' => null,
        ]);
        $contact = ContactPerson::query()->create([
            'full_name' => 'Billing Contact',
            'email' => 'billing@example.com',
            'phone' => null,
            'physical_address' => null,
        ]);
        $invoice = Invoice::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'contact_person_id' => $contact->id,
            'created_by_id' => null,
            'code' => 'AUTO-'.$suffix,
            'type' => 'IC',
            'stage' => 'initial',
            'status' => 'approved',
            'total_dr' => 100,
            'total_wodr' => 50,
            'grand_total' => 150,
            'balance_conciliation' => 150,
        ]);

        return [$invoice, $contact];
    }
}
