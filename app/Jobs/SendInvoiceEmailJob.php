<?php

namespace App\Jobs;

use App\Models\EmailLog;
use App\Models\User;
use App\Services\InvoiceEmailDeliveryService;
use App\Services\InvoicePdfService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendInvoiceEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        private readonly int $emailLogId,
        private readonly ?int $userId = null,
        private readonly ?string $ipAddress = null,
        private readonly ?string $userAgent = null,
    ) {
        $this->onQueue('emails');
    }

    public function handle(InvoicePdfService $pdfs, InvoiceEmailDeliveryService $delivery): void
    {
        $emailLog = EmailLog::query()
            ->with('invoice.pdfFile')
            ->find($this->emailLogId);

        if (! $emailLog) {
            return;
        }

        $user = $this->userId ? User::query()->find($this->userId) : null;

        if ($emailLog->invoice && ! $emailLog->invoice->pdfFile) {
            $pdfs->generate($emailLog->invoice, $user);
            $emailLog->load('invoice.pdfFile');
        }

        $delivery->send($emailLog, $user, $this->ipAddress, $this->userAgent);
    }
}
