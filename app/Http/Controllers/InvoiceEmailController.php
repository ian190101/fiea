<?php

namespace App\Http\Controllers;

use App\Actions\Email\PrepareInvoiceEmail;
use App\Http\Requests\PrepareInvoiceEmailRequest;
use App\Models\ContactAssignment;
use App\Models\ContactPerson;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceEmailQueueService;
use App\Services\InvoiceEmailTemplateService;
use App\Services\Security\ScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceEmailController extends Controller
{
    public function index(InvoiceEmailTemplateService $templates): Response
    {
        $actor = request()->user();
        $scope = app(ScopeService::class);

        return Inertia::render('InvoiceEmails/Index', [
            'invoices' => Inertia::defer(fn () => $this->invoices($actor, $scope), 'invoice-emails'),
            'defaultMessages' => Inertia::defer(fn () => $this->invoices($actor, $scope)
                ->getCollection()->mapWithKeys(fn (Invoice $invoice) => [$invoice->id => $templates->renderFor($invoice)])
                ->all(), 'invoice-emails'),
            'templates' => Inertia::defer(fn () => EmailTemplate::query()
                ->with('updatedBy:id,name,username')
                ->orderBy('invoice_type')
                ->orderBy('stage')
                ->get(), 'invoice-emails'),
            'templateTokens' => $templates->availableTokens(),
            'contacts' => Inertia::defer(fn () => ContactPerson::query()
                ->whereHas('assignments', fn ($q) => $q
                    ->whereIn('team_id', $scope->tripPhases($actor)->select('trip_phases.team_id'))
                    ->orWhere(fn ($c) => $c->whereNull('team_id')->whereIn('chapter_id', DB::table('teams')->select('chapter_id')
                        ->whereIn('id', $scope->tripPhases($actor)->select('trip_phases.team_id')))))
                ->whereNotNull('email')
                ->orderBy('full_name')
                ->limit(25)->get(['id', 'full_name', 'email']), 'invoice-emails'),
            'recommendedRecipients' => Inertia::defer(fn () => $this->recommendedRecipients(
                $this->invoices($actor, $scope)->getCollection()), 'invoice-emails'),
            'emailLogs' => Inertia::defer(fn () => EmailLog::query()
                ->whereHas('invoice', fn (Builder $q) => $scope->constrain($q, $actor, 'invoices.trip_phase_id'))
                ->with('invoice:id,code,type,stage')
                ->orderByDesc('created_at')
                ->orderByDesc('id')->cursorPaginate(25, ['*'], 'logs_cursor')
                ->through(function (EmailLog $log) use ($actor): array {
                    $row = $log->toArray();
                    if (! $actor->hasPermission('invoice_emails.manage')) {
                        $row['recipients_snapshot'] = array_values(array_filter($log->recipients_snapshot ?? [], fn ($r) => ($r['recipient_type'] ?? null) !== 'bcc'));
                    }

                    return $row;
                }), 'invoice-emails'),
            'automationSummary' => Inertia::defer(fn () => $this->automationSummary($actor, $scope), 'invoice-emails'),
        ]);
    }

    private function invoices(User $actor, ScopeService $scope)
    {
        return $scope->constrain(Invoice::query(), $actor, 'invoices.trip_phase_id')
            ->with([
                'tripPhase:id,project_id,team_id,phase',
                'tripPhase.project:id,code,name',
                'tripPhase.team:id,chapter_id,name',
                'contactPerson:id,full_name,email',
                'recipients' => fn ($q) => $q->when(! $actor->hasPermission('invoice_emails.manage'), fn ($r) => $r->where('recipient_type', '!=', 'bcc')),
                'emailLogs:id,invoice_id,subject,status,error_message,sent_at,created_at',
            ])
            ->withCount('emailLogs')
            ->orderByDesc('created_at')
            ->orderByDesc('id')->cursorPaginate(25, ['*'], 'invoices_cursor');
    }

    /**
     * @return array{automated_pending: int, queued: int, failed_due: int, max_retry_exceeded: int}
     */
    private function automationSummary(User $actor, ScopeService $scope): array
    {
        $base = fn () => EmailLog::query()->whereHas('invoice',
            fn (Builder $q) => $scope->constrain($q, $actor, 'invoices.trip_phase_id'));

        return [
            'automated_pending' => $base()->where('source', 'automation')->where('status', 'pending')->count(),
            'queued' => $base()->where('status', 'queued')->count(),
            'failed_due' => $base()
                ->where('status', 'failed')
                ->where(function ($query) {
                    $query->whereNull('next_retry_at')
                        ->orWhere('next_retry_at', '<=', now());
                })
                ->count(),
            'max_retry_exceeded' => $base()->where('status', 'failed')->where('retry_count', '>=', 3)->count(),
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function remember(string $key, int $seconds, callable $callback): mixed
    {
        if (app()->environment('testing')) {
            return $callback();
        }

        return Cache::remember("fiea:invoice-emails:{$key}", now()->addSeconds($seconds), $callback);
    }

    public function store(PrepareInvoiceEmailRequest $request, Invoice $invoice,
        PrepareInvoiceEmail $prepare): RedirectResponse
    {
        $prepare->execute($request->user(), $invoice, $request->validated());

        return back()->with('success', 'Correo preparado correctamente.');
    }

    public function send(
        Request $request,
        InvoiceEmailQueueService $emailQueue,
        EmailLog $emailLog
    ): RedirectResponse {
        $emailLog->loadMissing('invoice.pdfFile');

        if (! $emailLog->invoice) {
            return back()->withErrors(['email' => 'El correo no tiene invoice relacionado.']);
        }

        app(ScopeService::class)->authorize($request->user(), 'invoice_emails.manage', (int) $emailLog->invoice->trip_phase_id);

        if (! in_array($emailLog->status, ['pending', 'failed', 'queued'], true)) {
            return back()->withErrors(['email' => 'Solo se pueden enviar correos pendientes, en cola o fallidos.']);
        }

        $emailQueue->enqueue($emailLog, $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', 'Correo encolado correctamente. Se enviara en segundo plano.');
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
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
     * @param  array<int, array<string, mixed>>  $recipients
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
