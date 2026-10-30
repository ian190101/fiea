<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Data\Financial\RecordPaymentData;
use App\Models\AuditLog;
use App\Models\BillingCase;
use App\Models\CreditAccount;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\PaymentExpectation;
use App\Models\TreasuryPayment;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\Security\ScopeService;
use App\Support\Decimal as D;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TreasuryService
{
    public function __construct(private readonly ScopeService $scope, private readonly CreditService $credits) {}

    public function record(User $actor, RecordPaymentData $data): TreasuryPayment
    {
        return DB::transaction(function () use ($actor, $data): TreasuryPayment {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $phaseId = BillingCase::query()->whereKey($data->caseId)->value('trip_phase_id');
            abort_unless($phaseId, 404);
            $phase = TripPhase::query()->lockForUpdate()->findOrFail($phaseId);
            $case = BillingCase::query()->lockForUpdate()->findOrFail($data->caseId);
            $this->scope->authorize($actor, 'payments.manage', (int) $phase->id);
            abort_unless(in_array($data->leg, ['chapter_to_eia', 'eia_to_fiea'], true)
                && in_array($data->stage, ['initial', 'final'], true)
                && in_array($data->direction, ['receipt', 'refund'], true), 422);
            abort_unless(D::compare($data->amount, '0') > 0 && D::money($data->amount) === $data->amount, 422);
            $hash = hash('sha256', json_encode(get_object_vars($data), JSON_THROW_ON_ERROR));
            $existing = TreasuryPayment::query()->where('idempotency_key', $data->idempotencyKey)
                ->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->billing_case_id === (int) $case->id
                    && hash_equals($existing->payload_hash, $hash), 409);

                return $existing;
            }
            abort_if($case->lifecycle_status === 'ANULADO', 409);
            if ($case->lifecycle_status === 'CERRADO') {
                abort_unless($actor->hasPermission('billing_cases.adjust_closed')
                    && mb_strlen(trim((string) $data->note)) >= 10, 403);
            }
            abort_unless(in_array($data->method, ['CHECK', 'ACH', 'CREDIT_CARD', 'PAYPAL', 'TRANSFER', 'OTHER'], true), 422);
            abort_unless((int) $case->version === $data->expectedCaseVersion, 409);
            $invoice = $data->invoiceId === null ? null : Invoice::query()->lockForUpdate()->findOrFail($data->invoiceId);
            abort_unless($invoice === null || ((int) $invoice->billing_case_id === (int) $case->id
                && $invoice->stage === $data->stage), 422);
            $reversed = null;
            if ($data->direction === 'refund') {
                abort_unless(filled($data->reference), 422, 'La devolución requiere referencia original.');
                $candidates = TreasuryPayment::query()->where('billing_case_id', $case->id)
                    ->where('leg', $data->leg)->where('direction', 'receipt')
                    ->where('reference', $data->reference)->where('stage', $data->stage)
                    ->when($invoice, fn ($q) => $q->where('invoice_id', $invoice->id))
                    ->orderBy('id')->lockForUpdate()->limit(2)->get();
                abort_unless($candidates->count() === 1, 422, 'Selecciona una referencia inequívoca del cobro original.');
                $reversed = $candidates->first();
                $refunded = D::sum(TreasuryPayment::query()->where('reverses_payment_id', $reversed->id)
                    ->where('direction', 'refund')->pluck('amount')->all());
                abort_unless(D::compare(D::sub($reversed->amount, $refunded), $data->amount) >= 0, 422);
            }
            $payment = TreasuryPayment::query()->create([
                'billing_case_id' => $case->id, 'invoice_id' => $invoice?->id,
                'leg' => $data->leg, 'stage' => $data->stage, 'direction' => $data->direction,
                'amount' => $data->amount, 'paid_on' => $data->paidOn,
                'method' => $data->method, 'reference' => $data->reference,
                'note' => $data->note, 'idempotency_key' => $data->idempotencyKey,
                'payload_hash' => $hash, 'reverses_payment_id' => $reversed?->id,
                'created_by_id' => $actor->id,
            ]);
            if ($data->direction === 'refund' && $data->leg === 'chapter_to_eia') {
                $earned = CreditEntry::query()->where('billing_case_id', $case->id)
                    ->where('kind', 'earned')->exists();
                if ($earned) {
                    $account = CreditAccount::query()->where('team_id', $phase->team_id)
                        ->where('currency', 'USD')->lockForUpdate()->firstOrFail();
                    $released = D::sum(CreditEntry::query()->where('billing_case_id', $case->id)
                        ->whereIn('kind', ['earned', 'refund'])->pluck('amount')->all());
                    $debit = D::compare($released, $data->amount) < 0 ? $released : $data->amount;
                    abort_unless(D::compare($debit, '0') >= 0 && D::compare($account->balance, $debit) >= 0, 409);
                    $account->forceFill(['balance' => D::money(D::sub($account->balance, $debit))])->save();
                    if (D::compare($debit, '0') > 0) {
                        CreditEntry::query()->create([
                            'credit_account_id' => $account->id, 'billing_case_id' => $case->id,
                            'invoice_id' => $invoice?->id, 'kind' => 'refund',
                            'amount' => D::money(D::sub('0', $debit)),
                            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => $hash,
                            'reason' => 'Devolución de cobro '.$payment->id,
                            'created_by_id' => $actor->id,
                        ]);
                    }
                }
            }
            $case->forceFill(['version' => $case->version + 1])->save();
            if ($case->lifecycle_status === 'CERRADO' && $data->leg === 'chapter_to_eia'
                && $data->direction === 'receipt' && $case->current_final_id !== null) {
                $final = Invoice::query()->whereKey($case->current_final_id)
                    ->whereIn('status', ['approved', 'sent', 'paid'])->lockForUpdate()->first();
                if ($final) {
                    $paid = D::sum(TreasuryPayment::query()->where('billing_case_id', $case->id)
                        ->where('leg', 'chapter_to_eia')->get(['direction', 'amount'])
                        ->map(fn (TreasuryPayment $p) => $p->direction === 'refund' ? D::sub('0', $p->amount) : $p->amount));
                    $applied = D::sub('0', D::sum(CreditEntry::query()->where('billing_case_id', $case->id)
                        ->where('kind', 'applied')->pluck('amount')->all()));
                    $released = D::sum(CreditEntry::query()->where('billing_case_id', $case->id)
                        ->whereIn('kind', ['earned', 'refund'])->pluck('amount')->all());
                    $excess = D::sub(D::sub(D::add($paid, $applied), $final->grand_total), $released);
                    if (D::compare($excess, '0') > 0) {
                        abort_unless($actor->hasPermission('credits.manage'), 403);
                        $this->credits->earn($actor, (int) $case->id, (string) Str::uuid());
                    }
                }
            }
            AuditLog::query()->create([
                'user_id' => $actor->id, 'action' => 'treasury_payment_recorded',
                'module' => 'treasury', 'auditable_type' => TreasuryPayment::class,
                'auditable_id' => $payment->id,
                'trip_phase_id' => $case->trip_phase_id,
                'metadata' => ['case_id' => $case->id, 'leg' => $data->leg,
                    'direction' => $data->direction, 'amount' => $data->amount],
            ]);

            return $payment;
        }, 3);
    }

    public function setExpectation(User $actor, int $caseId, string $leg, array $data): void
    {
        DB::transaction(function () use ($actor, $caseId, $leg, $data): void {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $phaseId = BillingCase::query()->whereKey($caseId)->value('trip_phase_id');
            abort_unless($phaseId, 404);
            $phase = TripPhase::query()->lockForUpdate()->findOrFail($phaseId);
            $case = BillingCase::query()->lockForUpdate()->findOrFail($caseId);
            $this->scope->authorize($actor, 'payments.manage', (int) $phase->id);
            abort_unless($leg === 'eia_to_fiea', 422);
            abort_unless((int) $case->version === (int) $data['version'], 409);
            abort_if($case->lifecycle_status === 'ANULADO', 409);
            if ($case->lifecycle_status === 'CERRADO') {
                abort_unless($actor->hasPermission('billing_cases.adjust_closed')
                    && mb_strlen(trim($data['reason'])) >= 10, 403);
            }
            abort_unless(D::compare($data['expected_amount'], '0') >= 0, 422);
            $invoice = isset($data['basis_invoice_id']) ? Invoice::query()->whereKey($data['basis_invoice_id'])
                ->where('billing_case_id', $case->id)->lockForUpdate()->firstOrFail() : null;
            abort_unless(in_array($data['stage'], ['initial', 'final'], true), 422);
            if ($invoice) {
                abort_unless($invoice->stage === $data['stage']
                    && in_array($invoice->status, ['approved', 'sent', 'paid'], true), 422, 'El fundamento debe estar vigente y corresponder a la etapa.');
            }
            $expectation = PaymentExpectation::query()->where('billing_case_id', $case->id)
                ->where('leg', $leg)->where('stage', $data['stage'])->lockForUpdate()->first();
            $before = $expectation?->only(['expected_amount', 'reason', 'version']);
            if (! $expectation) {
                $expectation = new PaymentExpectation;
            }
            $expectation->fill([
                'billing_case_id' => $case->id, 'leg' => $leg, 'stage' => $data['stage'],
                'expected_amount' => $data['expected_amount'], 'basis' => $invoice ? 'invoice' : 'manual',
                'basis_invoice_id' => $invoice?->id, 'source' => [],
                'reason' => $data['reason'], 'created_by_id' => $actor->id,
            ]);
            if ($expectation->exists) {
                $expectation->version++;
            }
            $expectation->save();
            $case->forceFill(['version' => $case->version + 1])->save();
            AuditLog::query()->create([
                'user_id' => $actor->id, 'action' => 'payment_expectation_updated',
                'module' => 'treasury', 'auditable_type' => PaymentExpectation::class,
                'auditable_id' => $expectation->id,
                'trip_phase_id' => $case->trip_phase_id,
                'metadata' => ['before' => $before, 'after' => $expectation->only(['expected_amount', 'reason', 'version'])],
            ]);
        }, 3);
    }
}
