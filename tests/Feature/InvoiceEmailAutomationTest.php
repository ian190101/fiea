<?php

namespace Tests\Feature;

use App\Jobs\SendInvoiceEmailJob;
use App\Models\Chapter;
use App\Models\BillingCase;
use App\Models\ChapterType;
use App\Models\ContactPerson;
use App\Models\Country;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceRecipient;
use App\Models\Project;
use App\Models\StorageFile;
use App\Models\Team;
use App\Models\TripPhase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceEmailAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.resend.idempotency_window_seconds', 3600);
    }

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
        Queue::fake();
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
        $this->freezeDelivery($emailLog, $invoice);

        $this->artisan('fiea:email-automation --send-pending')
            ->assertSuccessful();

        Queue::assertNotPushed(SendInvoiceEmailJob::class);
        $this->assertDatabaseHas('outbox_events', ['dedup_key' => 'invoice-email:'.$emailLog->delivery_key]);

        $this->assertSame('queued', $emailLog->refresh()->status);
        $this->assertNull($emailLog->sent_at);
        $this->assertNotNull($invoice->refresh()->pdf_file_id);
    }

    public function test_command_retries_due_failed_email(): void
    {
        Queue::fake();
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
        $this->freezeDelivery($emailLog, $invoice);

        $this->artisan('fiea:email-automation --retry-failed')
            ->assertSuccessful();

        Queue::assertNotPushed(SendInvoiceEmailJob::class);
        $this->assertDatabaseHas('outbox_events', ['dedup_key' => 'invoice-email:'.$emailLog->delivery_key]);

        $this->assertSame('queued', $emailLog->refresh()->status);
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
        $case = BillingCase::query()->create([
            'trip_phase_id' => $tripPhase->id, 'invoice_type' => 'IC',
            'billing_mode' => 'standard', 'metadata' => ['document_date' => '2026-07-01'],
        ]);
        Storage::fake('local');
        $objectKey = 'invoices/'.$tripPhase->id.'/approved.pdf';
        Storage::disk('local')->put($objectKey, '%PDF-1.4 documento aprobado');
        $file = StorageFile::query()->create([
            'provider' => 'local', 'bucket' => null, 'object_key' => $objectKey,
            'original_name' => 'approved.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => strlen('%PDF-1.4 documento aprobado'),
            'checksum' => hash('sha256', '%PDF-1.4 documento aprobado'), 'public_url' => null,
        ]);
        $contact = ContactPerson::query()->create([
            'full_name' => 'Billing Contact',
            'email' => 'billing@example.com',
            'phone' => null,
            'physical_address' => null,
        ]);
        $invoice = Invoice::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'pdf_file_id' => $file->id,
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
        $invoice->forceFill(['billing_case_id' => $case->id, 'snapshot' => [
            'type' => 'IC', 'stage' => 'initial', 'totals' => ['total' => '150.00'],
        ]])->save();

        return [$invoice, $contact];
    }

    private function freezeDelivery(EmailLog $log, Invoice $invoice): void
    {
        $file = $invoice->pdfFile;
        $log->forceFill([
            'delivery_key' => (string) str()->uuid(),
            'recipients_snapshot' => [['email' => 'billing@example.com', 'recipient_type' => 'to']],
            'attachment_snapshot' => [
                'storage_file_id' => $file->id, 'checksum' => $file->checksum,
                'filename' => $file->original_name, 'mime_type' => $file->mime_type,
            ],
            'payload_hash' => hash('sha256', $log->subject),
            'dispatch_deadline' => now()->addHour(),
        ])->save();
    }
}
