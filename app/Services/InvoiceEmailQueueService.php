<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BillingCase;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\OutboxEvent;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\Security\ScopeService;
use Illuminate\Support\Facades\DB;

class InvoiceEmailQueueService
{
    public function enqueue(EmailLog $emailLog, ?User $user = null, ?string $ip = null, ?string $userAgent = null): void
    {
        app(MailConfiguration::class)->apply();
        abort_unless((bool) config('mail.invoice_queue_enabled'), 503, 'El envío de correos está deshabilitado.');
        DB::transaction(function () use ($emailLog, $user, $ip, $userAgent): void {
            if ($user) {
                $user = User::query()->lockForUpdate()->findOrFail($user->id);
            }
            $invoiceId = EmailLog::query()->whereKey($emailLog->id)->value('invoice_id');
            $reference = Invoice::query()->findOrFail($invoiceId);
            TripPhase::query()->lockForUpdate()->findOrFail($reference->trip_phase_id);
            abort_unless($reference->billing_case_id !== null, 409, 'Revisa primero el documento histórico.');
            BillingCase::query()->lockForUpdate()->findOrFail($reference->billing_case_id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
            $log = EmailLog::query()->lockForUpdate()->findOrFail($emailLog->id);
            abort_unless(in_array($invoice->status, ['approved', 'sent', 'paid'], true), 409,
                'Solo se envían documentos emitidos.');
            if ($user) {
                app(ScopeService::class)->authorize(
                    $user, 'invoice_emails.manage', (int) $invoice->trip_phase_id);
            }
            abort_unless($log->delivery_key && $log->recipients_snapshot && $log->attachment_snapshot, 409,
                'Prepara y revisa el correo antes de enviarlo.');
            if ($log->status === 'sent') {
                return;
            }
            abort_if($log->status === 'unknown', 409, 'Revisa primero el estado con el proveedor.');
            abort_if($log->dispatch_deadline && now()->greaterThan($log->dispatch_deadline), 409,
                'Venció la ventana de despacho; prepara un correo nuevo.');
            $event = OutboxEvent::query()->firstOrCreate(
                ['dedup_key' => 'invoice-email:'.$log->delivery_key],
                ['type' => 'invoice-email', 'aggregate_id' => $log->id,
                    'payload' => ['email_log_id' => $log->id], 'status' => 'pending',
                    'available_at' => now()],
            );
            if ($log->status === 'failed' && $event->status === 'done') {
                $event->forceFill(['status' => 'pending', 'locked_until' => null, 'available_at' => now()])->save();
            }
            $log->forceFill(['status' => 'queued', 'error_message' => null, 'next_retry_at' => null])->save();
            AuditLog::query()->create([
                'user_id' => $user?->id, 'action' => 'invoice_email_queued', 'module' => 'emails',
                'auditable_type' => EmailLog::class, 'auditable_id' => $log->id,
                'trip_phase_id' => $invoice->trip_phase_id,
                'ip_address' => $ip, 'user_agent' => substr((string) $userAgent, 0, 500),
                'metadata' => ['invoice_id' => $invoice->id, 'delivery_key' => $log->delivery_key],
            ]);
        }, 3);
    }
}
