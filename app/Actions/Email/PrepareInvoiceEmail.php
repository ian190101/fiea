<?php

declare(strict_types=1);

namespace App\Actions\Email;

use App\Models\AuditLog;
use App\Models\BillingCase;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\InvoiceEmailDispatchPolicy;
use App\Services\InvoicePdfService;
use App\Services\Security\ScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PrepareInvoiceEmail
{
    public function __construct(private readonly InvoicePdfService $pdfs,
        private readonly ScopeService $scope) {}

    public function execute(User $actor, Invoice $invoice, array $data): EmailLog
    {
        $this->scope->authorize($actor, 'invoice_emails.manage', (int) $invoice->trip_phase_id);
        $window = app(InvoiceEmailDispatchPolicy::class)->windowSeconds();
        abort_unless($window > 60, 503, 'Configura el plazo de despacho del proveedor de correo.');
        $version = (int) $invoice->version;
        $snapshotHash = hash('sha256', json_encode($invoice->snapshot, JSON_THROW_ON_ERROR));
        $file = $this->pdfs->forPreparation($invoice, $actor);
        $recipients = collect($data['recipients'])->map(fn (array $row) => [
            'contact_person_id' => $row['contact_person_id'] ?? null,
            'email' => strtolower(trim($row['email'])),
            'recipient_type' => $row['recipient_type'],
        ])->unique(fn (array $row) => $row['email'].'|'.$row['recipient_type'])->values()->all();
        $attachment = ['storage_file_id' => $file->id, 'checksum' => $file->checksum,
            'filename' => $file->original_name, 'mime_type' => $file->mime_type];
        $payloadHash = hash('sha256', json_encode([
            'invoice_id' => $invoice->id, 'snapshot_hash' => $snapshotHash,
            'subject' => $data['subject'], 'body' => $data['body'],
            'recipients' => $recipients, 'attachment' => $attachment,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $invoice, $version, $snapshotHash,
            $data, $recipients, $attachment, $payloadHash, $window): EmailLog {
            User::query()->lockForUpdate()->findOrFail($actor->id);
            TripPhase::query()->lockForUpdate()->findOrFail($invoice->trip_phase_id);
            abort_unless($invoice->billing_case_id !== null, 409);
            BillingCase::query()->lockForUpdate()->findOrFail($invoice->billing_case_id);
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->scope->authorize($actor, 'invoice_emails.manage', (int) $locked->trip_phase_id);
            abort_unless((int) $locked->version === $version
                && hash('sha256', json_encode($locked->snapshot, JSON_THROW_ON_ERROR)) === $snapshotHash, 409,
                'El documento cambió durante la preparación.');
            abort_unless(in_array($locked->status, ['approved', 'sent', 'paid'], true), 409);
            $log = EmailLog::query()->create([
                'invoice_id' => $locked->id, 'subject' => $data['subject'], 'body' => $data['body'],
                'status' => 'pending', 'source' => 'manual', 'retry_count' => 0,
            ]);
            $log->forceFill([
                'delivery_key' => (string) Str::uuid(),
                'recipients_snapshot' => $recipients,
                'attachment_snapshot' => $attachment,
                'payload_hash' => $payloadHash,
                'dispatch_deadline' => now()->addSeconds($window - 60),
            ])->save();
            AuditLog::query()->create([
                'user_id' => $actor->id, 'action' => 'invoice_email_prepared', 'module' => 'emails',
                'auditable_type' => EmailLog::class, 'auditable_id' => $log->id,
                'trip_phase_id' => $locked->trip_phase_id,
                'metadata' => ['invoice_id' => $locked->id, 'payload_hash' => $payloadHash],
            ]);

            return $log;
        }, 3);
    }
}
