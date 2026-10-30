<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Data\Financial\ReconciliationInput;
use App\Models\AccountingReview;
use App\Models\BillingCase;
use App\Models\CreditAccount;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\PaymentExpectation;
use App\Models\TreasuryPayment;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\Security\AccountingReviewScope;
use App\Services\Security\ScopeService;
use App\Support\Decimal as D;
use Illuminate\Support\Collection;

final class ControlQuery
{
    public function __construct(private readonly ScopeService $scope, private readonly ReconciliationService $reconciliation) {}

    public function forYear(User $actor, int $year, ?string $cursor): array
    {
        return $this->forYearBasis($actor, $year, $cursor, 'case_lifetime');
    }

    public function rowsForYear(User $actor, int $year, ?string $cursor, string $basis = 'case_lifetime'): array
    {
        return $this->forYearBasis($actor, $year, $cursor, $basis, false);
    }

    public function forYearBasis(User $actor, int $year, ?string $cursor, string $basis, bool $aggregate = true): array
    {
        abort_unless($year >= 2000 && $year <= 2100, 422);
        abort_unless(in_array($basis, ['case_lifetime', 'period_cash'], true), 422);
        $query = BillingCase::query()
            ->whereIn('billing_cases.trip_phase_id', $this->scope->tripPhases($actor)->select('trip_phases.id'))
            ->when($basis === 'case_lifetime', fn ($q) => $q->whereHas('codes', fn ($codes) => $codes->where('year', $year)))
            ->when($basis === 'period_cash', fn ($q) => $q->whereHas('payments', fn ($payments) => $payments->whereYear('paid_on', $year)))
            ->with(['codes' => fn ($q) => $q->orderBy('year')->orderBy('id'),
                'tripPhase.project', 'tripPhase.team', 'currentInitial', 'currentFinal'])
            ->orderBy('billing_cases.id');
        $page = $query->cursorPaginate(25, ['billing_cases.*'], 'cursor', $cursor);
        $cases = $page->getCollection();
        $hasMore = $page->hasMorePages();
        $caseIds = $cases->pluck('id')->all();
        $payments = TreasuryPayment::query()->whereIn('billing_case_id', $caseIds)
            ->when($basis === 'period_cash', fn ($q) => $q->whereYear('paid_on', $year))
            ->get()->groupBy('billing_case_id');
        $entries = CreditEntry::query()->whereIn('billing_case_id', $caseIds)->get()->groupBy('billing_case_id');
        $expectations = PaymentExpectation::query()->whereIn('billing_case_id', $caseIds)->get()->groupBy('billing_case_id');
        $teamIds = $cases->pluck('tripPhase.team_id')->unique()->all();
        $accounts = CreditAccount::query()->whereIn('team_id', $teamIds)->where('currency', 'USD')->get()->keyBy('team_id');
        $reviews = $this->visibleReviews($actor, AccountingReview::query()->whereIn('team_id', $teamIds)->where('period_year', $year)->get())
            ->keyBy(fn (AccountingReview $review) => $review->project_id.':'.$review->team_id);

        $rows = [];
        foreach ($cases as $case) {
            $casePayments = $payments->get($case->id, collect());
            $caseEntries = $entries->get($case->id, collect());
            $eiaReceived = $this->net($casePayments->where('leg', 'chapter_to_eia'));
            $fieaReceived = $this->net($casePayments->where('leg', 'eia_to_fiea'));
            $applied = D::sub('0', D::sum($caseEntries->where('kind', 'applied')->pluck('amount')->all()));
            $document = $this->approved($case->currentFinal) ?? $this->approved($case->currentInitial);
            $eiaExpected = $basis === 'case_lifetime' ? $document?->grand_total : null;
            $eiaCoverage = D::add($eiaReceived, $applied);
            $fieaExpected = $expectations->get($case->id, collect())
                ->where('leg', 'eia_to_fiea')->sortByDesc(fn ($e) => $e->stage === 'final' ? 2 : 1)
                ->first()?->expected_amount;
            if ($basis === 'period_cash') {
                $fieaExpected = null;
            }
            $final = $basis === 'case_lifetime' ? $this->approved($case->currentFinal) : null;
            $phase = $case->tripPhase;
            $groupKey = $phase->project_id.':'.$phase->team_id;
            $review = $reviews->get($groupKey);
            $caseAccounting = ($review?->source ?? [])['case_totals'][$case->id] ?? null;
            if ($caseAccounting === null && $review && count($review->source['case_ids'] ?? []) === 1
                && (int) $review->source['case_ids'][0] === (int) $case->id) {
                $caseAccounting = $review->accounting_total;
            }
            $creditAccount = $accounts->get($phase->team_id);
            $credit = $creditAccount ? D::money(D::compare($creditAccount->balance, '0') > 0
                ? $creditAccount->balance : '0') : null;
            $reconciled = $this->reconciliation->reconcile(new ReconciliationInput(
                $eiaReceived, $final?->grand_total, $caseAccounting,
                $fieaExpected, $fieaReceived,
            ));
            $rows[] = [
                'billing_case_id' => $case->id, 'group_key' => $groupKey,
                'project_id' => $phase->project_id, 'team_id' => $phase->team_id,
                'project_code' => $phase->project?->code,
                'project_name' => $phase->project?->name,
                'invoice_codes' => $case->codes->pluck('code')->all(),
                'invoice_type' => $case->invoice_type, 'lifecycle_status' => $case->lifecycle_status,
                'eia' => ['expected' => $eiaExpected, 'received' => D::money($eiaReceived),
                    'coverage' => D::money($eiaCoverage), 'status' => $this->legStatus($eiaExpected, $eiaCoverage)],
                'fiea' => ['expected' => $fieaExpected, 'received' => D::money($fieaReceived),
                    'status' => $this->legStatus($fieaExpected, $fieaReceived)],
                'operating_total' => $final?->grand_total,
                'operating_dr' => $final?->total_dr, 'operating_wodr' => $final?->total_wodr,
                'accounting_total' => $caseAccounting,
                'difference' => $reconciled->operatingLessAccounting,
                'credit' => $credit,
                'balance_due' => $final?->balance_conciliation,
                'case_cash_surplus' => $final ? D::money(D::sub($eiaReceived, $final->grand_total)) : null,
                'credit_account_id' => $creditAccount?->id,
            ];
        }

        // Los recorridos de filas para CSV y alertas no vuelven a agregar todo el año en cada página.
        $groups = $aggregate ? $this->aggregateGroups($actor, $year, $basis) : [];
        $summaryIncome = D::money(D::sum(array_column($groups, 'income')));
        $pendingGroups = count(array_filter($groups, fn (array $g) => $g['operating_total'] === null));
        $summaryOperating = $pendingGroups === 0 ? D::money(D::sum(array_column($groups, 'operating_total'))) : null;
        $uniqueAccounts = [];
        foreach ($groups as $group) {
            if ($group['credit_account_id'] !== null) {
                $uniqueAccounts[$group['credit_account_id']] = $group['credit'];
            }
        }

        return [
            'rows' => $rows, 'group_totals' => array_values($groups),
            'summary' => $aggregate ? [
                'case_count' => array_sum(array_column($groups, 'case_count')),
                'income' => $summaryIncome, 'operating_total' => $summaryOperating,
                'known_operating_subtotal' => D::money(D::sum(array_column($groups, 'known_operating_subtotal'))),
                'operating_dr' => $pendingGroups === 0 ? D::money(D::sum(array_column($groups, 'known_operating_dr'))) : null,
                'operating_wodr' => $pendingGroups === 0 ? D::money(D::sum(array_column($groups, 'known_operating_wodr'))) : null,
                'pending_groups' => $pendingGroups,
                'difference' => $summaryOperating === null ? null : D::money(D::sub($summaryIncome, $summaryOperating)),
                'credit' => D::money(D::sum(array_values($uniqueAccounts))),
            ] : null,
            'next_cursor' => $hasMore ? $page->nextCursor()?->encode() : null,
            'basis' => $basis,
        ];
    }

    private function approved(?Invoice $invoice): ?Invoice
    {
        return $invoice && in_array($invoice->status, ['approved', 'sent', 'paid'], true) ? $invoice : null;
    }

    private function aggregateGroups(User $actor, int $year, string $basis): array
    {
        $groups = [];
        $query = BillingCase::query()
            ->whereIn('billing_cases.trip_phase_id', $this->scope->tripPhases($actor)->select('trip_phases.id'))
            ->when($basis === 'case_lifetime', fn ($q) => $q->whereHas('codes', fn ($codes) => $codes->where('year', $year)))
            ->when($basis === 'period_cash', fn ($q) => $q->whereHas('payments', fn ($payments) => $payments->whereYear('paid_on', $year)));
        $query->chunkById(500, function ($cases) use (&$groups, $year, $basis): void {
            $ids = $cases->pluck('id');
            $phases = TripPhase::query()->whereIn('id', $cases->pluck('trip_phase_id'))
                ->get(['id', 'project_id', 'team_id'])->keyBy('id');
            $payments = TreasuryPayment::query()->whereIn('billing_case_id', $ids)
                ->where('leg', 'chapter_to_eia')
                ->when($basis === 'period_cash', fn ($q) => $q->whereYear('paid_on', $year))
                ->get(['billing_case_id', 'direction', 'amount'])->groupBy('billing_case_id');
            $finals = Invoice::query()->whereIn('id', $cases->pluck('current_final_id')->filter())
                ->get(['id', 'status', 'grand_total', 'total_dr', 'total_wodr'])->keyBy('id');
            foreach ($cases as $case) {
                $phase = $phases->get($case->trip_phase_id);
                if (! $phase) {
                    continue;
                }
                $key = $phase->project_id.':'.$phase->team_id;
                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'group_key' => $key, 'project_id' => $phase->project_id, 'team_id' => $phase->team_id,
                        'income' => '0.00', 'known_operating_subtotal' => '0.00', 'pending_finals' => 0,
                        'known_operating_dr' => '0.00', 'known_operating_wodr' => '0.00',
                        'case_count' => 0,
                    ];
                }
                $groups[$key]['case_count']++;
                $net = $this->net($payments->get($case->id, collect()));
                $groups[$key]['income'] = D::money(D::add($groups[$key]['income'], $net));
                $final = $finals->get($case->current_final_id);
                if ($basis === 'period_cash' || ! $this->approved($final)) {
                    $groups[$key]['pending_finals']++;
                } else {
                    $groups[$key]['known_operating_subtotal'] = D::money(D::add(
                        $groups[$key]['known_operating_subtotal'], $final->grand_total));
                    $groups[$key]['known_operating_dr'] = D::money(D::add(
                        $groups[$key]['known_operating_dr'], $final->total_dr));
                    $groups[$key]['known_operating_wodr'] = D::money(D::add(
                        $groups[$key]['known_operating_wodr'], $final->total_wodr));
                }
            }
        }, 'billing_cases.id', 'id');
        $teamIds = array_values(array_unique(array_column($groups, 'team_id')));
        $accounts = CreditAccount::query()->whereIn('team_id', $teamIds)
            ->where('currency', 'USD')->get()->keyBy('team_id');
        $reviews = $this->visibleReviews($actor, AccountingReview::query()->whereIn('team_id', $teamIds)
            ->where('period_year', $year)->get())->keyBy(fn ($review) => $review->project_id.':'.$review->team_id);
        foreach ($groups as $key => &$group) {
            $operating = $group['pending_finals'] === 0 ? $group['known_operating_subtotal'] : null;
            $review = $reviews->get($key);
            $account = $accounts->get($group['team_id']);
            $group['operating_total'] = $operating;
            $group['difference'] = $operating === null ? null : D::money(D::sub($group['income'], $operating));
            $group['accounting_total'] = $review?->accounting_total;
            $group['operating_less_accounting'] = $operating === null || $review?->accounting_total === null
                ? null : D::money(D::sub($operating, $review->accounting_total));
            $group['credit'] = $account ? D::money(D::compare($account->balance, '0') > 0 ? $account->balance : '0') : null;
            $group['credit_account_id'] = $account?->id;
        }
        unset($group);

        return $groups;
    }

    private function net($payments): string
    {
        return D::sum($payments->map(fn (TreasuryPayment $p): string => $p->direction === 'refund' ? D::sub('0', $p->amount) : $p->amount));
    }

    private function visibleReviews(User $actor, Collection $reviews): Collection
    {
        return app(AccountingReviewScope::class)->visible($actor, $reviews);
    }

    private function legStatus(?string $expected, string $coverage): string
    {
        if ($expected === null) {
            return 'PENDIENTE';
        }
        if (D::compare($coverage, $expected) >= 0) {
            return 'PAGADO';
        }

        return D::compare($coverage, '0') <= 0 ? 'NO PAGADO' : 'PENDIENTE';
    }
}
