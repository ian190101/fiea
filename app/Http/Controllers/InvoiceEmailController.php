<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ContactAssignment;
use App\Models\ContactPerson;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\InvoiceRecipient;
use App\Services\InvoiceEmailDeliveryService;
use App\Services\InvoiceEmailQueueService;
use App\Services\InvoiceEmailTemplateService;
use App\Services\InvoicePdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceEmailController extends Controller
{
    public function index(InvoiceEmailTemplateService $templates): Response
    {
        return Inertia::render('InvoiceEmails/Index', [
            'invoices' => Inertia::defer(fn () => $this->invoices(), 'invoice-emails'),
            'defaultMessages' => Inertia::defer(fn () => $this->invoices()
                ->mapWithKeys(fn (Invoice $invoice) => [$invoice->id => $templates->renderFor($invoice)])
                ->all(), 'invoice-emails'),
            'templates' => Inertia::defer(fn () => EmailTemplate::query()
                ->with('updatedBy:id,name,username')
                ->orderBy('invoice_type')
                ->orderBy('stage')
                ->get(), 'invoice-emails'),
            'templateTokens' => $templates->availableTokens(),
            'contacts' => Inertia::defer(fn () => ContactPerson::query()
                ->whereNotNull('email')
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'email']), 'invoice-emails'),
            'recommendedRecipients' => Inertia::defer(fn () => $this->recommendedRecipients($this->invoices()), 'invoice-emails'),
            'emailLogs' => Inertia::defer(fn () => EmailLog::query()
                ->with('invoice:id,code,type,stage')
                ->orderByDesc('created_at')
                ->cursorPaginate(20), 'invoice-emails'),
            'automationSummary' => Inertia::defer(fn () => $this->automationSummary(), 'invoice-emails'),
        ]);
    }

    private function invoices()
    {
        return Invoice::query()
            ->with([
                'tripPhase:id,project_id,team_id,phase',
                'tripPhase.project:id,code,name',
                'tripPhase.team:id,chapter_id,name',
                'contactPerson:id,full_name,email',
                'recipients:id,invoice_id,contact_person_id,email,recipient_type',
                'emailLogs:id,invoice_id,subject,status,error_message,sent_at,created_at',
            ])
            ->withCount('emailLogs')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @return array{automated_pending: int, queued: int, failed_due: int, max_retry_exceeded: int}
     */
    private function automationSummary(): array
    {
        return $this->remember('automation-summary', 15, fn () => [
                'automated_pending' => EmailLog::query()->where('source', 'automation')->where('status', 'pending')->count(),
                'queued' => EmailLog::query()->where('status', 'queued')->count(),
                'failed_due' => EmailLog::query()
                    ->where('status', 'failed')
                    ->where(function ($query) {
                        $query->whereNull('next_retry_at')
                            ->orWhere('next_retry_at', '<=', now());
                    })
                    ->count(),
                'max_retry_exceeded' => EmailLog::query()->where('status', 'failed')->where('retry_count', '>=', 3)->count(),
            ]);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function remember(string $key, int $seconds, callable $callback): mixed
    {
        if (app()->environment('testing')) {
            return $callback();
        }

        return Cache::remember("fiea:invoice-emails:{$key}", now()->addSeconds($seconds), $callback);
    }

    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'recipients' => ['required', 'array', 'min:1', 'max:30'],
            'recipients.*.contact_person_id' => ['nullable', 'integer', Rule::exists('contact_people', 'id')],
            'recipients.*.email' => ['required', 'email:rfc', 'max:255'],
            'recipients.*.recipient_type' => ['required', 'string', Rule::in(['to', 'cc', 'bcc'])],
        ]);

        $validator->after(function ($validator) use ($request) {
            $recipients = collect($request->input('recipients', []));

            if (!$recipients->contains(fn ($recipient) => ($recipient['recipient_type'] ?? null) === 'to')) {
                $validator->errors()->add('recipients', 'Debe existir al menos un destinatario principal TO.');
            }
        });

        $data = $validator->validate();
        $recipients = $this->normalizedRecipients($data['recipients']);

        DB::transaction(function () use ($request, $invoice, $data, $recipients) {
            $invoice->recipients()->delete();

            foreach ($recipients as $recipient) {
                InvoiceRecipient::query()->create([
                    'invoice_id' => $invoice->id,
                    'contact_person_id' => $recipient['contact_person_id'],
                    'email' => $recipient['email'],
                    'recipient_type' => $recipient['recipient_type'],
                ]);
            }

            $emailLog = EmailLog::query()->create([
                'invoice_id' => $invoice->id,
                'subject' => $data['subject'],
                'body' => $data['body'],
                'status' => 'pending',
                'error_message' => null,
                'sent_at' => null,
            ]);

            AuditLog::query()->create([
                'user_id' => $request->user()?->id,
                'action' => 'invoice_email_prepared',
                'module' => 'emails',
                'auditable_type' => Invoice::class,
                'auditable_id' => $invoice->id,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'metadata' => [
                    'email_log_id' => $emailLog->id,
                    'subject' => $data['subject'],
                    'recipients' => $recipients,
                ],
            ]);
        });

        return back()->with('success', 'Correo preparado correctamente.');
    }

    public function send(
        Request $request,
        InvoiceEmailQueueService $emailQueue,
        InvoiceEmailDeliveryService $delivery,
        InvoicePdfService $pdfs,
        EmailLog $emailLog
    ): RedirectResponse {
        $emailLog->loadMissing('invoice.pdfFile');

        if (! $emailLog->invoice) {
            return back()->withErrors(['email' => 'El correo no tiene invoice relacionado.']);
        }

        if (! in_array($emailLog->status, ['pending', 'failed', 'queued'], true)) {
            return back()->withErrors(['email' => 'Solo se pueden enviar correos pendientes, en cola o fallidos.']);
        }

        if (! (bool) config('mail.invoice_queue_enabled', true)) {
            if (! $emailLog->invoice->pdfFile) {
                $pdfs->generate($emailLog->invoice, $request->user());
                $emailLog->load('invoice.pdfFile');
            }

            $delivery->send($emailLog, $request->user(), $request->ip(), $request->userAgent());
            $emailLog->refresh();

            if ($emailLog->status === 'failed') {
                return back()->withErrors(['email' => $emailLog->error_message ?: 'No se pudo enviar el correo.']);
            }

            return back()->with('success', 'Correo enviado correctamente.');
        }

        $emailQueue->enqueue($emailLog, $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', 'Correo encolado correctamente. Se enviara en segundo plano.');
    }

    /**
     * @param \Illuminate\Support\Collection<int, Invoice> $invoices
     * @return array<int, array<int, array{id: int|null, full_name: string, email: string, role: string, recipient_type: string}>>
     */
    private function recommendedRecipients($invoices): array
    {
        $teamIds = $invoices->pluck('tripPhase.team_id')->filter()->unique()->values();
        $chapterIds = $invoices->pluck('tripPhase.team.chapter_id')->filter()->unique()->values();

        $assignments = ContactAssignment::query()
            ->with('contactPerson:id,full_name,email')
            ->where('is_active', true)
            ->where('is_email_recipient', true)
            ->where(function ($query) use ($teamIds, $chapterIds) {
                $query->whereIn('team_id', $teamIds)
                    ->orWhereIn('chapter_id', $chapterIds);
            })
            ->get();

        return $invoices->mapWithKeys(function (Invoice $invoice) use ($assignments) {
            $teamId = $invoice->tripPhase?->team_id;
            $chapterId = $invoice->tripPhase?->team?->chapter_id;

            $recommended = $assignments
                ->filter(function (ContactAssignment $assignment) use ($teamId, $chapterId) {
                    return $assignment->team_id === $teamId || ($assignment->team_id === null && $assignment->chapter_id === $chapterId);
                })
                ->filter(fn (ContactAssignment $assignment) => filled($assignment->contactPerson?->email))
                ->map(fn (ContactAssignment $assignment) => [
                    'id' => $assignment->contact_person_id,
                    'full_name' => $assignment->contactPerson->full_name,
                    'email' => $assignment->contactPerson->email,
                    'role' => $assignment->role,
                    'recipient_type' => $assignment->is_billing_contact ? 'to' : 'cc',
                ])
                ->unique(fn (array $recipient) => strtolower($recipient['email']).'|'.$recipient['recipient_type'])
                ->values()
                ->all();

            return [$invoice->id => $recommended];
        })->all();
    }

    /**
     * @param array<int, array<string, mixed>> $recipients
     * @return array<int, array{contact_person_id: int|null, email: string, recipient_type: string}>
     */
    private function normalizedRecipients(array $recipients): array
    {
        return collect($recipients)
            ->map(fn (array $recipient) => [
                'contact_person_id' => $recipient['contact_person_id'] ?? null,
                'email' => strtolower(trim((string) $recipient['email'])),
                'recipient_type' => strtolower((string) $recipient['recipient_type']),
            ])
            ->unique(fn (array $recipient) => $recipient['email'].'|'.$recipient['recipient_type'])
            ->values()
            ->all();
    }
}
