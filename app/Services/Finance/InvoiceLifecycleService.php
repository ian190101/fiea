<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Data\Financial\CreateInvoiceData;
use App\Models\AuditLog;
use App\Models\BillingCase;
use App\Models\ContactAssignment;
use App\Models\Invoice;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\Security\ScopeService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class InvoiceLifecycleService
{
    public function __construct(
        private readonly ScopeService $scope,
        private readonly FinancialSnapshotBuilder $snapshots,
        private readonly InvoiceCodeAllocator $codes,
    ) {}

    public function create(User $actor, CreateInvoiceData $data): Invoice
    {
        try {
            return DB::transaction(function () use ($actor, $data): Invoice {
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                [$phase, $case] = $this->lockCase($data->caseId);
                abort_if($phase->status === 'cancelled', 409, 'No se pueden emitir documentos de un viaje cancelado.');
                $this->scope->authorize($actor, 'invoices.manage', (int) $phase->id);
                abort_unless(in_array($data->stage, ['initial', 'final'], true), 422);
                $hash = hash('sha256', json_encode(get_object_vars($data), JSON_THROW_ON_ERROR));

                $existing = Invoice::query()->where('idempotency_key', $data->idempotencyKey)
                    ->lockForUpdate()->first();
                if ($existing) {
                    abort_unless((int) $existing->created_by_id === (int) $actor->id
                        && (int) $existing->billing_case_id === (int) $case->id
                        && hash_equals((string) $existing->payload_hash, $hash), 409,
                        'La clave de operación ya se utilizó con otros datos.');

                    return $existing;
                }

                abort_unless((int) $case->version === $data->expectedVersion, 409,
                    'El caso cambió. Recarga y revisa antes de emitir.');
                abort_if(in_array($case->lifecycle_status, ['CERRADO', 'ANULADO'], true), 409,
                    'Este caso no admite nuevos documentos.');
                $pointer = $data->stage === 'initial' ? 'current_initial_id' : 'current_final_id';
                abort_unless($case->{$pointer} === null, 409, 'Ya existe un documento vigente de esta etapa.');
                abort_if($data->stage === 'initial' && $case->billing_mode === 'final_only', 422,
                    'Este caso se emite solamente como Final.');
                $this->assertContact($phase, $data->contactPersonId);

                $result = $this->snapshots->build($case, $data->stage, $data->contactPersonId);
                $invoice = new Invoice;
                $invoice->forceFill([
                    'trip_phase_id' => $phase->id,
                    'billing_case_id' => $case->id,
                    'contact_person_id' => $data->contactPersonId,
                    'created_by_id' => $actor->id,
                    'code' => $this->codes->allocate($case, $data->stage),
                    'type' => $case->invoice_type,
                    'stage' => $data->stage,
                    'status' => 'draft',
                    'version' => 1,
                    'idempotency_key' => $data->idempotencyKey,
                    'payload_hash' => $hash,
                ]);
                $this->applySnapshot($invoice, $result);
                $invoice->save();
                $case->forceFill([$pointer => $invoice->id, 'version' => $case->version + 1])->save();
                $this->audit($actor, $invoice, 'invoice_draft_created');

                return $invoice;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // Otra transacción pudo ganar la misma clave mientras esta esperaba el índice único.
            $existing = Invoice::query()->where('idempotency_key', $data->idempotencyKey)->first();
            if (! $existing) {
                abort(409, 'El código ya está reservado. Revisa la numeración del caso.');
            }
            $hash = hash('sha256', json_encode(get_object_vars($data), JSON_THROW_ON_ERROR));
            $actor = User::query()->findOrFail($actor->id);
            $this->scope->authorize($actor, 'invoices.manage', (int) $existing->trip_phase_id);
            abort_unless((int) $existing->created_by_id === (int) $actor->id
                && (int) $existing->billing_case_id === $data->caseId
                && hash_equals((string) $existing->payload_hash, $hash), 409,
                'La clave de operación ya se utilizó con otros datos.');

            return $existing;
        }
    }

    public function refreshDraft(User $actor, int $invoiceId, int $expectedVersion): Invoice
    {
        return DB::transaction(function () use ($actor, $invoiceId, $expectedVersion): Invoice {
            [$actor, $phase, $case, $invoice] = $this->lockInvoice($actor, $invoiceId);
            abort_if($phase->status === 'cancelled', 409, 'No se pueden actualizar documentos de un viaje cancelado.');
            Gate::forUser($actor)->authorize('update', $invoice);
            $this->assertDraft($invoice, $expectedVersion);
            $this->applySnapshot($invoice, $this->snapshots->build($case, $invoice->stage, $invoice->contact_person_id));
            $invoice->version++;
            $invoice->save();
            $this->audit($actor, $invoice, 'invoice_draft_refreshed');

            return $invoice;
        }, 3);
    }

    public function approve(User $actor, int $invoiceId, int $expectedVersion): Invoice
    {
        return DB::transaction(function () use ($actor, $invoiceId, $expectedVersion): Invoice {
            [$actor, $phase, $case, $invoice] = $this->lockInvoice($actor, $invoiceId);
            abort_if($phase->status === 'cancelled', 409, 'No se pueden aprobar documentos de un viaje cancelado.');
            Gate::forUser($actor)->authorize('approve', $invoice);
            $this->assertDraft($invoice, $expectedVersion);
            $result = $this->snapshots->build($case, $invoice->stage, $invoice->contact_person_id);
            abort_unless($this->fingerprint($result['snapshot'])
                === $this->fingerprint($invoice->snapshot ?? []), 409,
                'La matriz cambió. Actualiza el borrador y revisa los nuevos importes.');
            $invoice->forceFill([
                'status' => 'approved',
                'approved_by_id' => $actor->id,
                'issued_on' => today()->toDateString(),
                'due_on' => $invoice->stage === 'initial'
                    ? $phase->starts_on->copy()->subDays(14)->toDateString() : null,
                'locked_at' => now(),
                'version' => $invoice->version + 1,
            ])->save();
            $this->audit($actor, $invoice, 'invoice_approved');

            // La entrega externa se programa en outbox, fuera de los bloqueos SQL.
            return $invoice;
        }, 3);
    }

    public function void(User $actor, int $invoiceId, int $expectedVersion, string $reason): Invoice
    {
        return DB::transaction(function () use ($actor, $invoiceId, $expectedVersion, $reason): Invoice {
            [$actor, $phase, $case, $invoice] = $this->lockInvoice($actor, $invoiceId);
            Gate::forUser($actor)->authorize('void', $invoice);
            abort_unless((int) $invoice->version === $expectedVersion, 409);
            abort_unless($invoice->status === 'draft', 409,
                'Un documento emitido requiere una reversión contable explícita.');
            abort_unless(mb_strlen(trim($reason)) >= 10 && mb_strlen($reason) <= 2000, 422);
            $invoice->forceFill([
                'status' => 'void', 'locked_at' => now(), 'version' => $invoice->version + 1,
            ])->save();
            $pointer = $invoice->stage === 'initial' ? 'current_initial_id' : 'current_final_id';
            if ((int) $case->{$pointer} === (int) $invoice->id) {
                $case->forceFill([$pointer => null, 'version' => $case->version + 1])->save();
            }
            $this->audit($actor, $invoice, 'invoice_voided', ['reason' => trim($reason)]);

            return $invoice;
        }, 3);
    }

    private function lockCase(int $caseId): array
    {
        $phaseId = BillingCase::query()->whereKey($caseId)->value('trip_phase_id');
        abort_unless($phaseId !== null, 404);
        $phase = TripPhase::query()->lockForUpdate()->findOrFail($phaseId);
        $case = BillingCase::query()->lockForUpdate()->findOrFail($caseId);
        abort_unless((int) $case->trip_phase_id === (int) $phase->id, 409);

        return [$phase, $case];
    }

    private function lockInvoice(User $actor, int $invoiceId): array
    {
        $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
        $caseId = Invoice::query()->whereKey($invoiceId)->value('billing_case_id');
        abort_unless($caseId !== null, 409,
            'Primero debe vincularse y revisarse este documento histórico.');
        [$phase, $case] = $this->lockCase((int) $caseId);
        abort_unless($actor->is_active && $this->scope->permits($actor, (int) $phase->id), 404);
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
        abort_unless((int) $invoice->billing_case_id === (int) $case->id, 409);
        abort_if(in_array($case->lifecycle_status, ['CERRADO', 'ANULADO'], true), 409);

        return [$actor, $phase, $case, $invoice];
    }

    private function assertDraft(Invoice $invoice, int $version): void
    {
        abort_unless((int) $invoice->version === $version, 409,
            'Otra persona actualizó el documento. Recarga la vista.');
        abort_unless($invoice->status === 'draft' && $invoice->locked_at === null, 409,
            'Solo se puede cambiar un borrador.');
    }

    private function assertContact(TripPhase $phase, ?int $contactId): void
    {
        if ($contactId === null) {
            return;
        }
        $phase->loadMissing('team');
        $allowed = ContactAssignment::query()->where('contact_person_id', $contactId)
            ->where('is_active', true)->where('is_billing_contact', true)
            ->where(function ($q) use ($phase): void {
                $q->where('team_id', $phase->team_id)->orWhere(function ($q) use ($phase): void {
                    $q->whereNull('team_id')->where('chapter_id', $phase->team->chapter_id);
                });
            })->exists();
        abort_unless($allowed, 422, 'El contacto no pertenece al equipo o capítulo.');
    }

    private function applySnapshot(Invoice $invoice, array $result): void
    {
        $totals = $result['totals'];
        $invoice->forceFill([
            'total_dr' => $totals->totalDr,
            'total_wodr' => $totals->totalWodr,
            'grand_total' => $totals->total,
            'balance_conciliation' => $totals->balanceDue,
            'snapshot' => $result['snapshot'],
            'initial_invoice_id' => $result['initialInvoiceId'],
            'calculation_version' => $result['snapshot']['calculation_version'],
        ]);
    }

    private function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($this->canonicalize($snapshot), JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function audit(User $actor, Invoice $invoice, string $action, array $extra = []): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->id, 'action' => $action, 'module' => 'invoices',
            'auditable_type' => Invoice::class, 'auditable_id' => $invoice->id,
            'trip_phase_id' => $invoice->trip_phase_id,
            'metadata' => [
                'case_id' => $invoice->billing_case_id,
                'trip_phase_id' => $invoice->trip_phase_id,
                'version' => $invoice->version,
                'snapshot_hash' => $this->fingerprint($invoice->snapshot ?? []),
                ...$extra,
            ],
        ]);
    }
}
