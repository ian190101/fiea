<?php

namespace App\Services;

use App\Mail\InvoicePreparedMail;
use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\InvoiceRecipient;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

class InvoiceEmailDeliveryService
{
    public function __construct(private readonly SystemNotificationService $notifications)
    {
    }

    public function send(EmailLog $emailLog, ?User $user = null, ?string $ip = null, ?string $userAgent = null, bool $throwOnFailure = false): void
    {
        $emailLog->loadMissing(['invoice.recipients', 'invoice.pdfFile']);
        $invoice = $emailLog->invoice;

        if (! $invoice) {
            $this->markFailed($emailLog, 'El correo no tiene invoice relacionado.', $user, $ip, $userAgent);
            return;
        }

        $recipients = $invoice->recipients;
        $to = $this->emailsByType($recipients, 'to');

        if ($to === []) {
            $this->markFailed($emailLog, 'Debe existir al menos un destinatario principal TO.', $user, $ip, $userAgent);
            return;
        }

        try {
            $emailLog->forceFill([
                'last_attempted_at' => now(),
            ])->save();

            Mail::to($to)
                ->cc($this->emailsByType($recipients, 'cc'))
                ->bcc($this->emailsByType($recipients, 'bcc'))
                ->send(new InvoicePreparedMail($emailLog));

            $emailLog->forceFill([
                'status' => 'sent',
                'error_message' => null,
                'next_retry_at' => null,
                'sent_at' => now(),
            ])->save();

            $invoice->forceFill([
                'status' => $invoice->status === 'approved' ? 'sent' : $invoice->status,
                'sent_at' => $invoice->sent_at ?? now(),
            ])->save();

            $this->audit($emailLog, 'invoice_email_sent', $user, $ip, $userAgent);
            $this->notifications->notifyPermission(
                permission: 'invoice_emails.view',
                type: 'invoice_email_sent',
                severity: 'info',
                title: 'Correo de invoice enviado',
                body: "El correo de {$invoice->code} fue enviado correctamente.",
                actionUrl: route('invoice-emails.index'),
                actor: $user,
                data: ['invoice_id' => $invoice->id, 'email_log_id' => $emailLog->id],
            );
        } catch (Throwable $exception) {
            $this->markFailed($emailLog, $exception->getMessage(), $user, $ip, $userAgent);

            if ($throwOnFailure) {
                throw $exception;
            }
        }
    }

    /**
     * @param \Illuminate\Support\Collection<int, InvoiceRecipient> $recipients
     * @return array<int, string>
     */
    private function emailsByType($recipients, string $type): array
    {
        return $recipients
            ->where('recipient_type', $type)
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function markFailed(EmailLog $emailLog, string $message, ?User $user, ?string $ip, ?string $userAgent): void
    {
        $emailLog->forceFill([
            'status' => 'failed',
            'retry_count' => $emailLog->retry_count + 1,
            'last_attempted_at' => now(),
            'next_retry_at' => now()->addMinutes($this->retryDelayMinutes($emailLog->retry_count + 1)),
            'error_message' => str($message)->limit(2000)->toString(),
            'sent_at' => null,
        ])->save();

        $this->audit($emailLog, 'invoice_email_failed', $user, $ip, $userAgent, [
            'error' => $emailLog->error_message,
        ]);
        $this->notifications->notifyPermission(
            permission: 'invoice_emails.manage',
            type: 'invoice_email_failed',
            severity: 'critical',
            title: 'Correo de invoice fallido',
            body: $emailLog->error_message,
            actionUrl: route('invoice-emails.index'),
            actor: $user,
            data: ['invoice_id' => $emailLog->invoice_id, 'email_log_id' => $emailLog->id],
        );
    }

    private function retryDelayMinutes(int $retryCount): int
    {
        return match (true) {
            $retryCount <= 1 => 15,
            $retryCount === 2 => 60,
            default => 240,
        };
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function audit(EmailLog $emailLog, string $action, ?User $user, ?string $ip, ?string $userAgent, array $extra = []): void
    {
        AuditLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'module' => 'emails',
            'auditable_type' => EmailLog::class,
            'auditable_id' => $emailLog->id,
            'ip_address' => $ip,
            'user_agent' => substr((string) $userAgent, 0, 500),
            'metadata' => [
                'invoice_id' => $emailLog->invoice_id,
                'subject' => $emailLog->subject,
                ...$extra,
            ],
        ]);
    }
}
