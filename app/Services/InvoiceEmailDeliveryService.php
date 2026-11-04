<?php

namespace App\Services;

use App\Exceptions\MailPreparationException;
use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceRecipient;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class InvoiceEmailDeliveryService
{
    public function __construct(
        private readonly SystemNotificationService $notifications,
        private readonly TransactionalMailService $mail,
    ) {}

    public function send(EmailLog $emailLog, ?User $user = null, ?string $ip = null, ?string $userAgent = null, bool $throwOnFailure = false): void
    {
        $emailLog->loadMissing(['invoice']);
        $invoice = $emailLog->invoice;

        if (! $invoice) {
            $this->markFailed($emailLog, 'El correo no tiene invoice relacionado.', $user, $ip, $userAgent);

            return;
        }

        if (! $emailLog->delivery_key || ! $emailLog->recipients_snapshot || ! $emailLog->attachment_snapshot) {
            abort(409, 'El correo legado requiere una nueva preparación revisada.');
        }
        if ($emailLog->status === 'sent' || $emailLog->status === 'unknown') {
            return;
        }
        abort_if($emailLog->dispatch_deadline && now()->greaterThan($emailLog->dispatch_deadline), 409,
            'Venció la ventana de despacho; revisa el estado con el proveedor.');
        $recipients = collect($emailLog->recipients_snapshot);
        $to = $this->emailsByType($recipients, 'to');

        if ($to === []) {
            $this->markFailed($emailLog, 'Debe existir al menos un destinatario principal TO.', $user, $ip, $userAgent);

            return;
        }

        try {
            $emailLog->forceFill([
                'last_attempted_at' => now(),
                // SMTP no deduplica: si el proceso se interrumpe, se exige revisión antes de reenviar.
                'status' => config('services.transactional_mail.provider') === 'smtp' ? 'unknown' : $emailLog->status,
            ])->save();

            $providerMessageId = $this->mail->sendInvoice(
                emailLog: $emailLog,
                to: $to,
                cc: $this->emailsByType($recipients, 'cc'),
                bcc: $this->emailsByType($recipients, 'bcc'),
            );

            if ($providerMessageId === '') {
                throw new \RuntimeException('El proveedor no devolvió identificador.');
            }
            DB::transaction(function () use ($emailLog, $invoice, $providerMessageId, $user, $ip, $userAgent): void {
                $locked = EmailLog::query()->lockForUpdate()->findOrFail($emailLog->id);
                $locked->forceFill(['status' => 'sent', 'error_message' => null,
                    'next_retry_at' => null, 'sent_at' => now(),
                    'provider_message_id' => $providerMessageId])->save();
                $current = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
                $current->forceFill(['status' => $current->status === 'approved' ? 'sent' : $current->status,
                    'sent_at' => $current->sent_at ?? now()])->save();
                $this->audit($locked, 'invoice_email_sent', $user, $ip, $userAgent,
                    ['provider_message_id' => $providerMessageId]);
            });
            try {
                $this->notifications->notifyPermission(
                    permission: 'invoice_emails.view', type: 'invoice_email_sent',
                    severity: 'info', title: 'Correo de invoice enviado',
                    body: "El correo de {$invoice->code} fue enviado correctamente.",
                    actionUrl: route('invoice-emails.index'), actor: $user,
                    data: ['invoice_id' => $invoice->id, 'email_log_id' => $emailLog->id],
                );
            } catch (Throwable $notificationError) {
                report($notificationError);
            }
        } catch (Throwable $exception) {
            if ($exception instanceof MailPreparationException) {
                $this->markFailed($emailLog, 'No se completó la preparación local; no se inició el envío.', $user, $ip, $userAgent);
                report($exception);
                if ($throwOnFailure) {
                    throw $exception;
                }

                return;
            }
            // Tras una llamada HTTP ambigua se bloquea el reenvío automático.
            $reference = (string) Str::uuid();
            $emailLog->forceFill(['status' => 'unknown', 'error_message' => $reference,
                'next_retry_at' => null, 'last_attempted_at' => now()])->save();
            report($exception);
            $this->audit($emailLog, 'invoice_email_unknown', $user, $ip, $userAgent,
                ['error_reference' => $reference]);
            if ($throwOnFailure) {
                throw $exception;
            }
        }
    }

    /**
     * @param  Collection<int, InvoiceRecipient>  $recipients
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
     * @param  array<string, mixed>  $extra
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
