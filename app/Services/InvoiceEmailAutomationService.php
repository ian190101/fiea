<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InvoiceEmailAutomationService
{
    public function __construct(
        private readonly InvoiceEmailTemplateService $templates,
        private readonly InvoicePdfService $pdfs,
        private readonly InvoiceEmailQueueService $queue,
    ) {
    }

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
        $prepared = 0;

        Invoice::query()
            ->with(['recipients', 'tripPhase.project', 'tripPhase.team'])
            ->where('status', 'approved')
            ->whereHas('recipients', fn ($query) => $query->where('recipient_type', 'to'))
            ->whereDoesntHave('emailLogs', fn ($query) => $query->whereIn('status', ['pending', 'sent']))
            ->orderBy('id')
            ->chunkById(50, function ($invoices) use (&$prepared) {
                foreach ($invoices as $invoice) {
                    DB::transaction(function () use ($invoice, &$prepared) {
                        $message = $this->templates->renderFor($invoice);

                        $emailLog = EmailLog::query()->create([
                            'invoice_id' => $invoice->id,
                            'subject' => $message['subject'],
                            'body' => $message['body'],
                            'status' => 'pending',
                            'source' => 'automation',
                            'retry_count' => 0,
                            'error_message' => null,
                            'sent_at' => null,
                        ]);

                        AuditLog::query()->create([
                            'user_id' => null,
                            'action' => 'invoice_email_auto_prepared',
                            'module' => 'emails',
                            'auditable_type' => EmailLog::class,
                            'auditable_id' => $emailLog->id,
                            'ip_address' => null,
                            'user_agent' => 'system:email-automation',
                            'metadata' => [
                                'invoice_id' => $invoice->id,
                                'subject' => $emailLog->subject,
                            ],
                        ]);

                        $prepared++;
                    });
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
                    $this->ensurePdf($log);
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
                    $summary['retried']++;
                    $this->ensurePdf($log);
                    $this->queue->enqueue($log, null, null, 'system:email-automation');
                    $summary['queued']++;
                }
            });

        return $summary;
    }

    private function ensurePdf(EmailLog $log): void
    {
        if ($log->invoice && ! $log->invoice->pdfFile) {
            $this->pdfs->generate($log->invoice);
            $log->load('invoice.pdfFile');
        }
    }
}
