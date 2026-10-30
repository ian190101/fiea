<?php
declare(strict_types=1);
namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\BillingCase;
use App\Models\CreditAccount;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\TreasuryPayment;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\Security\ScopeService;
use App\Support\Decimal as D;
use Illuminate\Support\Facades\DB;

final class CreditService
{
    public function __construct(private readonly ScopeService $scope) {}

    public function apply(User $actor, int $accountId, int $caseId, string $amount, string $idempotencyKey): CreditEntry
    {
        return DB::transaction(function () use ($actor, $accountId, $caseId, $amount, $idempotencyKey): CreditEntry {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $phaseId = BillingCase::query()->whereKey($caseId)->value('trip_phase_id');
            abort_unless($phaseId, 404);
            $phase = TripPhase::query()->lockForUpdate()->findOrFail($phaseId);
            $case = BillingCase::query()->lockForUpdate()->findOrFail($caseId);
            $this->scope->authorize($actor, 'credits.manage', (int) $phase->id);
            $invoice = $this->currentInvoice($case);
            abort_unless($invoice, 422, 'Falta documento vigente aprobado.');
            $hash = hash('sha256', json_encode([$accountId, $caseId, $amount], JSON_THROW_ON_ERROR));
            $existing = CreditEntry::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->created_by_id === (int) $actor->id
                    && hash_equals($existing->payload_hash, $hash), 409);
                return $existing;
            }
            abort_if(in_array($case->lifecycle_status, ['CERRADO', 'ANULADO'], true), 409);
            abort_unless(D::compare($amount, '0') > 0 && D::money($amount) === $amount, 422);
            $account = CreditAccount::query()->lockForUpdate()->findOrFail($accountId);
            abort_unless((int) $account->team_id === (int) $phase->team_id && $account->currency === 'USD', 404);
            abort_unless(D::compare($account->balance, $amount) >= 0, 409, 'Crédito insuficiente.');
            $received = $this->netPayments($case->id);
            $applied = D::sub('0', D::sum(CreditEntry::query()->where('billing_case_id', $case->id)
                ->where('kind', 'applied')->pluck('amount')->all()));
            $due = D::sub(D::sub($invoice->grand_total, $received), $applied);
            abort_unless(D::compare($due, $amount) >= 0, 409, 'El crédito supera la deuda pendiente.');
            $entry = CreditEntry::query()->create([
                'credit_account_id' => $account->id, 'billing_case_id' => $case->id,
                'invoice_id' => $invoice->id, 'kind' => 'applied',
                'amount' => D::money(D::sub('0', $amount)),
                'idempotency_key' => $idempotencyKey, 'payload_hash' => $hash,
                'reason' => 'Aplicación a obligación vigente', 'created_by_id' => $actor->id,
            ]);
            $account->forceFill(['balance' => D::money(D::sub($account->balance, $amount))])->save();
            $case->forceFill(['version' => $case->version + 1])->save();
            $this->audit($actor, $entry);
            return $entry;
        }, 3);
    }

    public function earn(User $actor, int $caseId, string $idempotencyKey): CreditEntry
    {
        return DB::transaction(function () use ($actor, $caseId, $idempotencyKey): CreditEntry {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $phaseId = BillingCase::query()->whereKey($caseId)->value('trip_phase_id');
            abort_unless($phaseId, 404);
            $phase = TripPhase::query()->lockForUpdate()->findOrFail($phaseId);
            $case = BillingCase::query()->lockForUpdate()->findOrFail($caseId);
            $this->scope->authorize($actor, 'credits.manage', (int) $phase->id);
            abort_unless($case->lifecycle_status === 'CERRADO', 409);
            $invoice = Invoice::query()->whereKey($case->current_final_id)
                ->where('billing_case_id', $case->id)->whereIn('status', ['approved', 'sent', 'paid'])
                ->lockForUpdate()->first();
            abort_unless($invoice, 422, 'Final vigente no aprobado.');
            $hash = hash('sha256', json_encode([$caseId, $invoice->id], JSON_THROW_ON_ERROR));
            $existing = CreditEntry::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->created_by_id === (int) $actor->id
                    && (int) $existing->billing_case_id === $caseId
                    && hash_equals($existing->payload_hash, $hash), 409);
                return $existing;
            }
            DB::table('credit_accounts')->insertOrIgnore([
                'team_id' => $phase->team_id, 'currency' => 'USD', 'balance' => '0.00',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $account = CreditAccount::query()->where('team_id', $phase->team_id)
                ->where('currency', 'USD')->lockForUpdate()->firstOrFail();
            $payments = $this->netPayments($case->id);
            $applied = D::sub('0', D::sum(CreditEntry::query()->where('billing_case_id', $case->id)
                ->where('kind', 'applied')->pluck('amount')->all()));
            $released = D::sum(CreditEntry::query()->where('billing_case_id', $case->id)
                ->whereIn('kind', ['earned', 'refund'])->pluck('amount')->all());
            $excess = D::sub(D::sub(D::add($payments, $applied), $invoice->grand_total), $released);
            abort_unless(D::compare($excess, '0') > 0, 409, 'No existe excedente acreditable.');
            $amount = D::money($excess);
            $closureKey = 'case:'.$case->id.':invoice:'.$invoice->id.':case-version:'.$case->version;
            $entry = CreditEntry::query()->create([
                'credit_account_id' => $account->id, 'billing_case_id' => $case->id,
                'invoice_id' => $invoice->id, 'kind' => 'earned', 'amount' => $amount,
                'idempotency_key' => $idempotencyKey, 'payload_hash' => $hash,
                'closure_key' => $closureKey, 'reason' => 'Excedente confirmado al cierre',
                'created_by_id' => $actor->id,
            ]);
            $account->forceFill(['balance' => D::money(D::add($account->balance, $amount))])->save();
            $case->forceFill(['version' => $case->version + 1])->save();
            $this->audit($actor, $entry);
            return $entry;
        }, 3);
    }

    private function currentInvoice(BillingCase $case): ?Invoice
    {
        foreach ([$case->current_final_id, $case->current_initial_id] as $id) {
            if ($id === null) continue;
            $invoice = Invoice::query()->whereKey($id)->where('billing_case_id', $case->id)
                ->whereIn('status', ['approved', 'sent', 'paid'])->lockForUpdate()->first();
            if ($invoice) return $invoice;
        }
        return null;
    }

    private function netPayments(int $caseId): string
    {
        $rows = TreasuryPayment::query()->where('billing_case_id', $caseId)
            ->where('leg', 'chapter_to_eia')->get(['direction', 'amount']);
        return D::sum($rows->map(fn (TreasuryPayment $p): string =>
            $p->direction === 'refund' ? D::sub('0', $p->amount) : $p->amount));
    }

    private function audit(User $actor, CreditEntry $entry): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->id, 'action' => 'credit_'.$entry->kind,
            'module' => 'credits', 'auditable_type' => CreditEntry::class,
            'auditable_id' => $entry->id,
            'trip_phase_id' => \App\Models\BillingCase::query()
                ->whereKey($entry->billing_case_id)->value('trip_phase_id'),
            'metadata' => ['case_id' => $entry->billing_case_id, 'amount' => $entry->amount],
        ]);
    }
}
