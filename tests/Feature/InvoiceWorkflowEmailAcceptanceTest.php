<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\Financial\UpdateBudgetData;
use App\Models\BillingCase;
use App\Models\Chapter;
use App\Models\ChapterType;
use App\Models\ContactPerson;
use App\Models\Country;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Team;
use App\Models\TripPhase;
use App\Services\Finance\BudgetService;
use App\Services\InvoiceEmailTemplateService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\ExplicitAccess;
use Tests\TestCase;

final class InvoiceWorkflowEmailAcceptanceTest extends TestCase
{
    use ExplicitAccess;
    use RefreshDatabase;

    public function test_contacto_presupuesto_emision_aprobacion_pdf_cola_y_correo_ic_mat_initial_final(): void
    {
        $transport = $this->configureDelivery();
        Storage::fake('local');
        $permissions = ['contacts.manage', 'budgets.manage', 'actual_expenses.manage',
            'invoices.manage', 'invoices.view', 'invoices.approve', 'invoice_emails.manage'];
        $actor = $this->authorizedUser($permissions);
        $reviewer = $this->authorizedUser($permissions);
        $country = Country::query()->create(['name' => 'País de prueba']);
        $kind = ChapterType::query()->create(['name' => 'Tipo de prueba']);
        $chapter = Chapter::query()->create(['chapter_type_id' => $kind->id, 'name' => 'Capítulo de prueba']);
        $team = Team::query()->create(['chapter_id' => $chapter->id, 'name' => 'Equipo de prueba']);
        $project = Project::query()->create(['country_id' => $country->id, 'code' => 'PRUEBA-CORREO', 'name' => 'Proyecto de prueba']);
        $phase = TripPhase::query()->create(['project_id' => $project->id, 'team_id' => $team->id,
            'phase' => 'Initial Visit', 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-11',
            'volunteer_count' => 8, 'staff_count' => 1, 'status' => 'draft']);
        $this->actingAs($actor)->post(route('contacts.store'), ['full_name' => 'Contacto de prueba de correo',
            'email' => $this->recipient(), 'phone' => '555-PRUEBA', 'physical_address' => 'Dirección de prueba'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $contact = ContactPerson::query()->firstOrFail();
        $this->post(route('contact-assignments.store'), ['contact_person_id' => $contact->id,
            'chapter_id' => $chapter->id, 'team_id' => $team->id, 'role' => 'Billing Contact',
            'is_billing_contact' => true, 'is_email_recipient' => true, 'is_active' => true])
            ->assertRedirect()->assertSessionHasNoErrors();

        foreach (['IC', 'MAT'] as $type) {
            $case = BillingCase::query()->create(['trip_phase_id' => $phase->id, 'invoice_type' => $type,
                'billing_mode' => 'standard', 'metadata' => ['document_date' => '2026-10-30']]);
            $budget = app(BudgetService::class);
            $line = $budget->addLine($actor, $case->id, ['version' => $case->fresh()->version,
                'catalog_key' => $type === 'IC' ? 'ic.food_1' : 'mat.material.custom',
                'description_en' => 'Material de prueba', 'unit' => 'Each']);
            foreach (['initial', 'final'] as $stage) {
                $budget->update($actor, $phase->id, new UpdateBudgetData($type, $stage, $case->fresh()->version, [[
                    'id' => $line->id, 'unit_cost' => '10.0000', 'quantity' => $type === 'MAT' ? '2.0000' : null,
                    'receipt_number' => $stage === 'final' ? 'PRUEBA-001' : null,
                    'cells' => $type === 'IC' ? [['date' => '2026-11-10', 'quantity' => '2.0000',
                        'receipt_number' => $stage === 'final' ? 'PRUEBA-001' : null]] : [],
                ]]));
                $this->actingAs($actor)->post(route('invoices.store'), ['billing_case_id' => $case->id,
                    'stage' => $stage, 'version' => $case->fresh()->version, 'idempotency_key' => (string) Str::uuid(),
                    'contact_person_id' => $contact->id])->assertRedirect()->assertSessionHasNoErrors();
                $invoice = Invoice::query()->where('billing_case_id', $case->id)->where('stage', $stage)->firstOrFail();
                $this->actingAs($reviewer)->post(route('invoices.approve', $invoice), ['version' => $invoice->version])
                    ->assertRedirect()->assertSessionHasNoErrors();
                $selected = $this->actingAs($actor)->getJson(route('options.lookup', ['kind' => 'invoice-contacts',
                    'invoice_id' => $invoice->id, 'q' => $contact->email]))->assertOk()->json('data.0');
                self::assertSame($contact->id, $selected['id']);
                $template = app(InvoiceEmailTemplateService::class)->renderFor($invoice);
                $this->post(route('invoice-emails.store', $invoice), [
                    'subject' => '[PRUEBA] '.$template['subject'],
                    'body' => "PRUEBA / TEST EMAIL - No payment is required.\n\n".$template['body'],
                    'recipients' => [['contact_person_id' => $selected['id'], 'email' => $selected['email'], 'recipient_type' => 'to']],
                ])->assertRedirect()->assertSessionHasNoErrors();
                $log = EmailLog::query()->where('invoice_id', $invoice->id)->firstOrFail();
                self::assertStringStartsWith('%PDF', Storage::disk('local')->get($invoice->fresh()->pdfFile->object_key));
                $this->post(route('invoice-emails.send', $log))->assertRedirect()->assertSessionHasNoErrors();
                $this->artisan('fiea:dispatch-outbox')->assertSuccessful();
                self::assertSame(1, DB::table('jobs')->where('queue', 'emails')->count());
                $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'emails', '--once' => true, '--tries' => 1])
                    ->assertSuccessful();
                self::assertSame('sent', $log->refresh()->status, 'El correo de '.$type.' '.$stage.' debe completarse.');
                self::assertNotEmpty($log->provider_message_id);
                self::assertSame('sent', $invoice->refresh()->status);
                self::assertSame(0, DB::table('failed_jobs')->count());
                $this->artisan('fiea:dispatch-outbox')->assertSuccessful();
                self::assertSame(0, DB::table('jobs')->count());
            }
        }
        if ($transport !== null) {
            self::assertCount(4, $transport->messages());
        }
        $this->assertDatabaseCount('email_logs', 4);
    }

    public function test_correos_de_recuperacion_y_verificacion_usan_el_mismo_remitente_smtp(): void
    {
        $transport = $this->configureDelivery();
        $user = $this->authorizedUser([]);
        $user->forceFill(['email' => $this->recipient()])->save();
        $user->notify(new ResetPassword('token-de-prueba-no-utilizable'));
        $user->notify(new VerifyEmail);
        if ($transport !== null) {
            self::assertCount(2, $transport->messages());
            foreach ($transport->messages() as $sent) {
                self::assertSame($this->recipient(), $sent->getOriginalMessage()->getTo()[0]->getAddress());
                self::assertSame('ianvera395@gmail.com', $sent->getOriginalMessage()->getFrom()[0]->getAddress());
            }
        } else {
            self::assertTrue(true);
        }
    }

    private function recipient(): string
    {
        return getenv('FIEA_TEST_REAL_MAIL') === '1' ? 'ianvera395@gmail.com' : 'destinatario@example.test';
    }

    private function configureDelivery(): ?ArrayTransport
    {
        // El envío real requiere activación explícita y una base SQLite en memoria.
        $live = getenv('FIEA_TEST_REAL_MAIL') === '1';
        self::assertTrue(app()->environment('testing'));
        self::assertSame('sqlite', config('database.default'));
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['services.transactional_mail.provider' => 'smtp', 'mail.default' => 'smtp',
            'mail.from.address' => 'ianvera395@gmail.com', 'mail.from.name' => 'FIEA · Pruebas',
            'mail.mailers.smtp.require_tls' => true, 'mail.invoice_queue_enabled' => true,
            'queue.default' => 'database']);
        if (! $live) {
            config(['mail.mailers.smtp.host' => 'smtp.example.test', 'mail.mailers.smtp.username' => 'usuario',
                'mail.mailers.smtp.password' => 'clave-sintetica']);
        }
        Mail::purge('smtp');
        if ($live) {
            return null;
        }
        $transport = new ArrayTransport;
        Mail::mailer('smtp')->setSymfonyTransport($transport);

        return $transport;
    }
}
