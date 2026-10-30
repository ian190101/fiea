<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BillingCase;
use App\Models\ContactAssignment;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\OutboxEvent;
use App\Models\TripPhase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceEmailAutomationService
{
    public function __construct(
        private readonly InvoiceEmailTemplateService $templates,
        private readonly InvoicePdfService $pdfs,
        private readonly InvoiceEmailQueueService $queue,
    ) {}

    /**
     * @return array{prepared: int, sent: int, retried: int, failed: int}
     */
    public function run(bool $prepare = true, bool $sendPending = true, bool $retryFailed = true): array
    {
        $summary = [
            'prepared' => 0,
            'sent' => 0,
            'retried' => 0,
            'failed' => 0,
        ];

        if ($prepare) {
            $summary['prepared'] = $this->prepareApprovedInvoices();
        }

        if ($sendPending) {
            $sendSummary = $this->sendPendingAutomatedEmails();
            $summary['sent'] += $sendSummary['queued'];
        }

        if ($retryFailed) {
            $retrySummary = $this->retryFailedEmails();
            $summary['sent'] += $retrySummary['queued'];
            $summary['retried'] += $retrySummary['retried'];
        }

        return $summary;
    }

    public function prepareApprovedInvoices(): int
    {
        $window = (int) config('services.resend.idempotency_window_seconds', 0);
        if ($window <= 60) {
            return 0;
        }
        $prepared = 0;
        Invoice::query()
            ->with(['recipients', 'tripPhase.project', 'tripPhase.team'])
            ->where('status', 'approved')
            ->orderBy('id')
            ->chunkById(50, function ($invoices) use (&$prepared, $window) {
                foreach ($invoices as $invoice) {
                    if ($invoice->billing_case_id === null) {
                        continue;
                    }
                    $version = (int) $invoice->version;
                    $snapshotHash = hash('sha256', json_encode($invoice->snapshot, JSON_THROW_ON_ERROR));
                    $dedup = 'invoice-approved:'.$invoice->id.':'.$snapshotHash;
                    if (OutboxEvent::query()->where('dedup_key', $dedup)->exists()) {
                        continue;
                    }
                    $file = $invoice->pdfFile ?: $this->pdfs->generate($invoice);
                    $message = $this->templates->renderFor($invoice);
                    $recipients = $invoice->recipients->map(fn ($row) => [
                        'email' => $row->email, 'recipient_type' => $row->recipient_type,
                    ])->values()->all();
                    if (! collect($recipients)->contains('recipient_type', 'to')) {
                        $phase = $invoice->tripPhase;
                        $recipients = ContactAssignment::query()->where('is_active', true)->where('is_email_recipient', true)
                            ->where(fn ($q) => $q->where('team_id', $phase->team_id)
                                ->orWhere(fn ($c) => $c->whereNull('team_id')->where('chapter_id', $phase->team->chapter_id)))
                            ->with('contactPerson')->get()->pluck('contactPerson.email')->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
                            ->unique()->map(fn ($email) => ['email' => $email, 'recipient_type' => 'to'])->values()->all();
                    }
                    if ($recipients === []) {
                        continue;
                    }
                    $attachment = ['storage_file_id' => $file->id, 'checksum' => $file->checksum,
                        'filename' => $file->original_name, 'mime_type' => $file->mime_type];
                    DB::transaction(function () use ($invoice, $version, $snapshotHash, $dedup,
                        $message, $recipients, $attachment, $window, &$prepared): void {
                        TripPhase::query()->lockForUpdate()->findOrFail($invoice->trip_phase_id);
                        BillingCase::query()->lockForUpdate()->findOrFail($invoice->billing_case_id);
                        $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
                        abort_unless((int) $locked->version === $version
                            && hash('sha256', json_encode($locked->snapshot, JSON_THROW_ON_ERROR)) === $snapshotHash, 409);
                        if (OutboxEvent::query()->where('dedup_key', $dedup)->exists()) {
                            return;
                        }
                        $payloadHash = hash('sha256', json_encode([$locked->id, $snapshotHash,
                            $message, $recipients, $attachment], JSON_THROW_ON_ERROR));
                        $emailLog = EmailLog::query()->create([
                            'invoice_id' => $locked->id, 'subject' => $message['subject'],
                            'body' => $message['body'], 'status' => 'pending', 'source' => 'automation',
                            'retry_count' => 0,
                        ]);
                        $emailLog->forceFill([
                            'delivery_key' => (string) Str::uuid(),
                            'recipients_snapshot' => $recipients, 'attachment_snapshot' => $attachment,
                            'payload_hash' => $payloadHash,
                            'dispatch_deadline' => now()->addSeconds($window - 60),
                        ])->save();
                        OutboxEvent::query()->create([
                            'type' => 'invoice-approved', 'aggregate_id' => $emailLog->id,
                            'dedup_key' => $dedup, 'payload' => ['email_log_id' => $emailLog->id],
                            'status' => 'done', 'available_at' => now(),
                        ]);
                        AuditLog::query()->create([
                            'user_id' => null, 'action' => 'invoice_email_auto_prepared', 'module' => 'emails',
                            'auditable_type' => EmailLog::class, 'auditable_id' => $emailLog->id,
                            'trip_phase_id' => $locked->trip_phase_id,
                            'metadata' => ['invoice_id' => $locked->id, 'payload_hash' => $payloadHash],
                        ]);
                        $prepared++;
                    }, 3);
                }
            });

        return $prepared;
    }

    /**
     * @return array{queued: int, failed: int}
     */
    public function sendPendingAutomatedEmails(): array
    {
        $summary = ['queued' => 0, 'failed' => 0];

        EmailLog::query()
            ->with('invoice.pdfFile')
            ->where('source', 'automation')
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(50, function ($logs) use (&$summary) {
                foreach ($logs as $log) {
                    if (! $log->delivery_key || ! $log->recipients_snapshot || ! $log->attachment_snapshot) {
                        continue;
                    }
                    if ($log->dispatch_deadline && $log->dispatch_deadline->isPast()) {
                        $log->forceFill(['status' => 'expired', 'next_retry_at' => null])->save();
                        $summary['failed']++;

                        continue;
                    }
                    $this->queue->enqueue($log, null, null, 'system:email-automation');
                    $summary['queued']++;
                }
            });

        return $summary;
    }

    /**
     * @return array{queued: int, retried: int, failed: int}
     */
    public function retryFailedEmails(int $maxRetries = 3): array
    {
        $summary = ['queued' => 0, 'retried' => 0, 'failed' => 0];

        EmailLog::query()
            ->with('invoice.pdfFile')
            ->where('status', 'failed')
            ->where('retry_count', '<', $maxRetries)
            ->where(function ($query) {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('id')
            ->chunkById(50, function ($logs) use (&$summary) {
                foreach ($logs as $log) {
                    if (! $log->delivery_key || ! $log->recipients_snapshot || ! $log->attachment_snapshot) {
                        continue;
                    }
                    $summary['retried']++;
                    $this->queue->enqueue($log, null, null, 'system:email-automation');
                    $summary['queued']++;
                }
            });

        return $summary;
    }
}
