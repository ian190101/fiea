<?php

namespace App\Services;

use App\Jobs\SendInvoiceEmailJob;
use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

class InvoiceEmailQueueService
{
    public function enqueue(EmailLog $emailLog, ?User $user = null, ?string $ip = null, ?string $userAgent = null): void
    {
        $emailLog->forceFill([
            'status' => 'queued',
            'error_message' => null,
            'next_retry_at' => null,
        ])->save();

        AuditLog::query()->create([
            'user_id' => $user?->id,
            'action' => 'invoice_email_queued',
            'module' => 'emails',
            'auditable_type' => EmailLog::class,
            'auditable_id' => $emailLog->id,
            'ip_address' => $ip,
            'user_agent' => substr((string) $userAgent, 0, 500),
            'metadata' => [
                'invoice_id' => $emailLog->invoice_id,
                'subject' => $emailLog->subject,
            ],
        ]);

        $job = (new SendInvoiceEmailJob(
            emailLogId: $emailLog->id,
            userId: $user?->id,
            ipAddress: $ip,
            userAgent: $userAgent,
        ))->onConnection('database');

        Bus::dispatch($job);
    }
}
