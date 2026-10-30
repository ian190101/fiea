<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BillingCase;
use App\Models\Chapter;
use App\Models\ChapterType;
use App\Models\ContactPerson;
use App\Models\Country;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\InvoiceRecipient;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Models\StorageFile;
use App\Models\Team;
use App\Models\TripPhase;
use App\Models\User;
use App\Jobs\SendInvoiceEmailJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ExplicitAccess;
use Tests\TestCase;

class InvoiceEmailManagementTest extends TestCase
{
    use RefreshDatabase;
    use ExplicitAccess;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.resend.idempotency_window_seconds', 3600);
    }

    public function test_guests_are_redirected_from_invoice_emails(): void
    {
        $this->get('/correos-invoices')
            ->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_invoice_emails(): void
    {
        $user = $this->authorizedUser(['invoice_emails.view', 'invoice_emails.manage', 'invoices.view']);

        $this->actingAs($user)
            ->get('/correos-invoices')
            ->assertOk();
    }

    public function test_invoice_email_uses_configured_template_defaults(): void
    {
        $user = $this->authorizedUser(['invoice_emails.view', 'invoice_emails.manage', 'invoices.view']);
        [$invoice] = $this->createInvoiceContext();
        EmailTemplate::query()->create([
            'name' => 'Custom IC Initial',
            'invoice_type' => 'IC',
            'stage' => 'initial',
            'subject_template' => 'Custom {{invoice_code}} for {{project_code}}',
            'body_template' => 'Total due: {{grand_total}}',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get('/correos-invoices')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('templateTokens')
                ->loadDeferredProps('invoice-emails', fn (Assert $page) => $page
                    ->where("defaultMessages.{$invoice->id}.subject", "Custom {$invoice->code} for {$invoice->tripPhase->project->code}")
                    ->where("defaultMessages.{$invoice->id}.body", 'Total due: $150.00')
                    ->has('templates', 1)
                )
            );
    }

    public function test_authenticated_users_can_prepare_invoice_email(): void
    {
        $user = $this->authorizedUser(['invoice_emails.view', 'invoice_emails.manage', 'invoices.view']);
        [$invoice, $contact] = $this->createInvoiceContext();

        $this->actingAs($user)
            ->post(route('invoice-emails.store', $invoice->id), [
                'subject' => 'FIEA IC Initial Invoice',
                'body' => 'Hello, please find the invoice attached.',
                'recipients' => [
                    [
                        'contact_person_id' => $contact->id,
                        'email' => 'BILLING@EXAMPLE.COM',
                        'recipient_type' => 'to',
                    ],
                    [
                        'contact_person_id' => null,
                        'email' => 'copy@example.com',
                        'recipient_type' => 'cc',
                    ],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $prepared = EmailLog::query()->firstOrFail();
        $this->assertSame('billing@example.com', $prepared->recipients_snapshot[0]['email']);
        $this->assertSame('to', $prepared->recipients_snapshot[0]['recipient_type']);
        $this->assertNotNull($prepared->delivery_key);
        $this->assertDatabaseHas('email_logs', [
            'invoice_id' => $invoice->id,
            'subject' => 'FIEA IC Initial Invoice',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'invoice_email_prepared',
            'module' => 'emails',
            'auditable_type' => EmailLog::class,
            'auditable_id' => $prepared->id,
        ]);
    }

    public function test_invoice_email_requires_at_least_one_to_recipient(): void
    {
        $user = $this->authorizedUser(['invoice_emails.view', 'invoice_emails.manage', 'invoices.view']);
        [$invoice] = $this->createInvoiceContext();

        $this->actingAs($user)
            ->post(route('invoice-emails.store', $invoice->id), [
                'subject' => 'FIEA Invoice',
                'body' => 'Hello.',
                'recipients' => [
                    [
                        'contact_person_id' => null,
                        'email' => 'copy@example.com',
                        'recipient_type' => 'cc',
                    ],
                ],
            ])
            ->assertSessionHasErrors('recipients');

        $this->assertDatabaseCount('email_logs', 0);
    }

    public function test_preparaciones_conservan_destinatarios_inmutables(): void
    {
        $user = $this->authorizedUser(['invoice_emails.view', 'invoice_emails.manage', 'invoices.view']);
        [$invoice, $contact] = $this->createInvoiceContext();

        $this->actingAs($user)
            ->post(route('invoice-emails.store', $invoice->id), [
                'subject' => 'First',
                'body' => 'First body.',
                'recipients' => [
                    ['contact_person_id' => null, 'email' => 'first@example.com', 'recipient_type' => 'to'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('invoice-emails.store', $invoice->id), [
                'subject' => 'Second',
                'body' => 'Second body.',
                'recipients' => [
                    ['contact_person_id' => $contact->id, 'email' => 'billing@example.com', 'recipient_type' => 'to'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $logs = EmailLog::query()->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('first@example.com', $logs[0]->recipients_snapshot[0]['email']);
        $this->assertSame('billing@example.com', $logs[1]->recipients_snapshot[0]['email']);
        $this->assertSame(2, AuditLog::query()->where('module', 'emails')->count());
    }

    public function test_authenticated_users_can_create_email_template(): void
    {
        $user = $this->authorizedUser(['invoice_emails.view', 'invoice_emails.manage', 'invoices.view']);

        $this->actingAs($user)
            ->post(route('email-templates.store'), [
                'name' => 'MAT Final',
                'invoice_type' => 'MAT',
                'stage' => 'final',
                'subject_template' => 'FIEA {{invoice_type}} {{stage}} Invoice',
                'body_template' => 'Hello {{project_code}}',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('email_templates', [
            'name' => 'MAT Final',
            'invoice_type' => 'MAT',
            'stage' => 'final',
            'updated_by_id' => $user->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'email_template_created',
            'module' => 'email_templates',
            'auditable_type' => EmailTemplate::class,
        ]);
    }

    public function test_authenticated_users_can_update_email_template(): void
    {
        $user = $this->authorizedUser(['invoice_emails.view', 'invoice_emails.manage', 'invoices.view']);
        $template = EmailTemplate::query()->create([
            'name' => 'IC Initial',
            'invoice_type' => 'IC',
            'stage' => 'initial',
            'subject_template' => 'Old subject',
            'body_template' => 'Old body',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('email-templates.update', $template->id), [
                'name' => 'IC Initial Updated',
                'invoice_type' => 'IC',
                'stage' => 'initial',
                'subject_template' => 'New subject {{invoice_code}}',
                'body_template' => 'New body',
                'is_active' => false,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('email_templates', [
            'id' => $template->id,
            'name' => 'IC Initial Updated',
            'subject_template' => 'New subject {{invoice_code}}',
            'is_active' => false,
            'updated_by_id' => $user->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'email_template_updated',
            'module' => 'email_templates',
            'auditable_type' => EmailTemplate::class,
            'auditable_id' => $template->id,
        ]);
    }

    public function test_authenticated_users_can_send_prepared_invoice_email(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = $this->authorizedUser(['invoice_emails.view', 'invoice_emails.manage', 'invoices.view']);
        [$invoice, $contact] = $this->createInvoiceContext();
        $emailLog = $this->preparedLog($invoice, [
            ['email' => 'billing@example.com', 'recipient_type' => 'to'],
        ]);

        $this->actingAs($user)
            ->post(route('invoice-emails.send', $emailLog->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        Queue::assertNotPushed(SendInvoiceEmailJob::class);
        $this->assertDatabaseHas('outbox_events', ['dedup_key' => 'invoice-email:'.$emailLog->delivery_key]);
        $this->assertSame('queued', $emailLog->refresh()->status);
    }

    public function test_invoice_email_job_sends_prepared_invoice_email(): void
    {
        Http::fake([
            'api.resend.com/*' => Http::response(['id' => 'resend-message-1'], 200),
        ]);
        config(['services.resend.key' => 're_test_key']);
        Storage::fake('local');

        $user = $this->authorizedUser(['invoice_emails.view', 'invoice_emails.manage', 'invoices.view']);
        [$invoice, $contact] = $this->createInvoiceContext();
        $emailLog = $this->preparedLog($invoice, [
            ['email' => 'billing@example.com', 'recipient_type' => 'to'],
        ]);
        $event = OutboxEvent::query()->create([
            'dedup_key' => 'invoice-email:'.$emailLog->delivery_key,
            'type' => 'invoice-email', 'aggregate_id' => $emailLog->id,
            'payload' => ['email_log_id' => $emailLog->id], 'status' => 'pending',
            'available_at' => now(),
        ]);
        $emailLog->forceFill(['status' => 'queued'])->save();

        (new SendInvoiceEmailJob($emailLog->id, $user->id, null, null, $event->id))
            ->handle(app(\App\Services\InvoiceEmailDeliveryService::class));

        Http::assertSent(fn ($request) => $request->url() === 'https://api.resend.com/emails'
            && $request['to'] === ['billing@example.com']
            && $request['subject'] === 'FIEA Invoice'
            && count($request['attachments'] ?? []) === 1);
        $emailLog->refresh();
        $invoice->refresh();

        $this->assertSame('sent', $emailLog->status);
        $this->assertNotNull($emailLog->sent_at);
        $this->assertNotNull($invoice->pdf_file_id);
        $this->assertSame('sent', $invoice->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'invoice_email_sent',
            'module' => 'emails',
            'auditable_type' => EmailLog::class,
            'auditable_id' => $emailLog->id,
        ]);
    }

    public function test_correo_sin_to_se_encola_para_validacion_del_despacho(): void
    {
        Queue::fake();

        $user = $this->authorizedUser(['invoice_emails.view', 'invoice_emails.manage', 'invoices.view']);
        [$invoice] = $this->createInvoiceContext();
        $emailLog = $this->preparedLog($invoice, [
            ['email' => 'copy@example.com', 'recipient_type' => 'cc'],
        ]);

        $this->actingAs($user)
            ->post(route('invoice-emails.send', $emailLog->id))
            ->assertSessionHasNoErrors();

        Queue::assertNotPushed(SendInvoiceEmailJob::class);
        $this->assertDatabaseHas('outbox_events', ['dedup_key' => 'invoice-email:'.$emailLog->delivery_key]);
        $this->assertSame('queued', $emailLog->refresh()->status);
    }

    public function test_invoice_email_job_marks_log_as_failed_without_to_recipient(): void
    {
        Http::fake();

        $user = $this->authorizedUser(['invoice_emails.view', 'invoice_emails.manage', 'invoices.view']);
        [$invoice] = $this->createInvoiceContext();
        $emailLog = $this->preparedLog($invoice, [
            ['email' => 'copy@example.com', 'recipient_type' => 'cc'],
        ]);
        $event = OutboxEvent::query()->create([
            'dedup_key' => 'invoice-email:'.$emailLog->delivery_key,
            'type' => 'invoice-email', 'aggregate_id' => $emailLog->id,
            'payload' => ['email_log_id' => $emailLog->id], 'status' => 'pending',
            'available_at' => now(),
        ]);
        $emailLog->forceFill(['status' => 'queued'])->save();

        try {
            (new SendInvoiceEmailJob($emailLog->id, $user->id, null, null, $event->id))
                ->handle(app(\App\Services\InvoiceEmailDeliveryService::class));
        } catch (\RuntimeException) {
            //
        }

        Http::assertNothingSent();
        $this->assertSame('failed', $emailLog->refresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'invoice_email_failed',
            'module' => 'emails',
            'auditable_type' => EmailLog::class,
            'auditable_id' => $emailLog->id,
        ]);
    }

    public function test_invoice_email_send_is_queued_when_queue_is_not_sync(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);

        $user = $this->authorizedUser(['invoice_emails.view', 'invoice_emails.manage', 'invoices.view']);
        [$invoice, $contact] = $this->createInvoiceContext();
        $emailLog = $this->preparedLog($invoice, [
            ['email' => 'billing@example.com', 'recipient_type' => 'to'],
        ]);

        $this->actingAs($user)
            ->post(route('invoice-emails.send', $emailLog->id))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success')
            ->assertRedirect();

        Queue::assertNotPushed(SendInvoiceEmailJob::class);
        $this->assertDatabaseHas('outbox_events', ['dedup_key' => 'invoice-email:'.$emailLog->delivery_key]);
        $this->assertSame('queued', $emailLog->refresh()->status);
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
            'code' => 'EMAIL-'.$suffix,
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

    /** @param list<array{email: string, recipient_type: string}> $recipients */
    private function preparedLog(Invoice $invoice, array $recipients): EmailLog
    {
        $file = $invoice->pdfFile;
        $log = EmailLog::query()->create([
            'invoice_id' => $invoice->id, 'subject' => 'FIEA Invoice',
            'body' => 'Hello, please find the invoice attached.', 'status' => 'pending',
        ]);
        $log->forceFill([
            'delivery_key' => (string) str()->uuid(), 'recipients_snapshot' => $recipients,
            'attachment_snapshot' => [
                'storage_file_id' => $file->id, 'checksum' => $file->checksum,
                'filename' => $file->original_name, 'mime_type' => $file->mime_type,
            ],
            'payload_hash' => hash('sha256', json_encode($recipients, JSON_THROW_ON_ERROR)),
            'dispatch_deadline' => now()->addHour(),
        ])->save();
        return $log->fresh();
    }
}
