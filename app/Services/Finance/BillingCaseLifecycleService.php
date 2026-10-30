<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\AccountingReview;
use App\Models\AuditLog;
use App\Models\BillingCase;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\TreasuryPayment;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\Security\ScopeService;
use App\Support\Decimal as D;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class BillingCaseLifecycleService
{
    public function __construct(private readonly ScopeService $scope, private readonly CreditService $credits) {}

    public function transition(User $actor, int $caseId, string $target, int $expectedVersion, string $reason): void
    {
        DB::transaction(function () use ($actor, $caseId, $target, $expectedVersion, $reason): void {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $phaseId = BillingCase::query()->whereKey($caseId)->value('trip_phase_id');
            abort_unless($phaseId, 404);
            $phase = TripPhase::query()->lockForUpdate()->findOrFail($phaseId);
            $case = BillingCase::query()->lockForUpdate()->findOrFail($caseId);
            Gate::forUser($actor)->authorize('transition', $case);
            abort_unless((int) $case->version === $expectedVersion, 409);
            $from = $case->lifecycle_status;
            $allowed = [
                'ABIERTO' => ['POR GASTAR', 'FINAL PEND', 'ANULADO'],
                'POR GASTAR' => ['FINAL PEND', 'ANULADO'],
                'FINAL PEND' => ['CERRADO', 'ANULADO'],
            ];
            abort_unless(in_array($target, $allowed[$from] ?? [], true), 409);
            $motivated = mb_strlen(trim($reason)) >= 10;
            $final = $case->current_final_id ? Invoice::query()->lockForUpdate()->find($case->current_final_id) : null;
            if ($target === 'POR GASTAR') {
                abort_unless($case->lines()->whereHas('versions')->exists(), 422);
                $movements = TreasuryPayment::query()->where('billing_case_id', $case->id)->where('leg', 'chapter_to_eia')->get(['direction', 'amount']);
                $net = D::sum($movements->map(fn (TreasuryPayment $p): string => $p->direction === 'refund' ? D::sub('0', $p->amount) : $p->amount));
                $financed = D::compare($net, '0') > 0;
                abort_unless($financed || ($motivated && $actor->hasPermission('billing_cases.adjust_closed')), 422);
            }
            if ($target === 'FINAL PEND') {
                abort_unless($phase->status === 'completed'
                    || $case->lines()->whereHas('versions', fn ($q) => $q->where('stage', 'final'))->exists()
                    || ($motivated && $actor->hasPermission('billing_cases.adjust_closed')), 422);
            }
            if ($target === 'CERRADO') {
                abort_unless($final && in_array($final->status, ['approved', 'sent', 'paid'], true), 422);
                $review = AccountingReview::query()->where('project_id', $phase->project_id)
                    ->where('team_id', $phase->team_id)->where('period_year', app(FinancialPeriod::class)->year($case, $final))
                    ->lockForUpdate()->first();
                $reviewed = $review && in_array($case->id, $review->source['case_ids'] ?? [], true)
                    && $review->reviewed_at && $review->difference !== null
                    && D::compare($review->difference, '0') === 0;
                abort_unless($reviewed || ($motivated && $actor->hasPermission('billing_cases.adjust_closed')), 422);
                abort_unless(filled($case->treasury_note)
                    || TreasuryPayment::query()->where('billing_case_id', $case->id)->exists()
                    || ($motivated && $actor->hasPermission('billing_cases.adjust_closed')), 422);
                $payments = TreasuryPayment::query()->where('billing_case_id', $case->id)
                    ->where('leg', 'chapter_to_eia')->get(['direction', 'amount']);
                $paid = D::sum($payments->map(fn (TreasuryPayment $p) => $p->direction === 'refund'
                    ? D::sub('0', $p->amount) : $p->amount));
                $applied = D::sub('0', D::sum(CreditEntry::query()->where('billing_case_id', $case->id)
                    ->where('kind', 'applied')->pluck('amount')->all()));
                $released = D::sum(CreditEntry::query()->where('billing_case_id', $case->id)
                    ->whereIn('kind', ['earned', 'refund'])->pluck('amount')->all());
                $excess = D::sub(D::sub(D::add($paid, $applied), $final->grand_total), $released);
                $case->forceFill(['lifecycle_status' => 'CERRADO'])->save();
                if (D::compare($excess, '0') > 0) {
                    abort_unless($actor->hasPermission('credits.manage'), 403);
                    $this->credits->earn($actor, $case->id, (string) Str::uuid());
                    $case->refresh();
                }
            }
            if ($target === 'ANULADO') {
                abort_unless($motivated, 422);
                abort_unless(! TreasuryPayment::query()->where('billing_case_id', $case->id)->exists()
                    && ! CreditEntry::query()->where('billing_case_id', $case->id)->exists(), 409,
                    'Los movimientos requieren reversión documentada antes de anular.');
            }
            $case->forceFill([
                'lifecycle_status' => $target, 'version' => $case->version + 1,
                'closed_at' => $target === 'CERRADO' ? now() : null,
                'void_reason' => $target === 'ANULADO' ? trim($reason) : null,
            ])->save();
            AuditLog::query()->create([
                'user_id' => $actor->id, 'action' => 'billing_case_transitioned',
                'module' => 'billing_cases', 'auditable_type' => BillingCase::class,
                'auditable_id' => $case->id,
                'trip_phase_id' => $case->trip_phase_id,
                'metadata' => ['from' => $from, 'to' => $target, 'reason' => trim($reason)],
            ]);
        }, 3);
    }
}
