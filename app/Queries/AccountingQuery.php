<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\AccountingReview;
use App\Models\BillingCase;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Finance\FinancialPeriod;
use App\Services\Security\AccountingReviewScope;
use App\Services\Security\ScopeService;
use App\Support\Decimal as D;

final class AccountingQuery
{
    public function __construct(private readonly ScopeService $scope, private readonly FinancialPeriod $period) {}

    /** @return array<int, AccountingReview> */
    private function reviews(User $actor): array
    {
        $reviews = AccountingReview::query()->whereIn('project_id', $this->scope->tripPhases($actor)->select('project_id'))
            ->orderByDesc('period_year')->orderByDesc('id')->get();
        $reviews = app(AccountingReviewScope::class)->visible($actor, $reviews);
        $ids = $reviews->flatMap(fn (AccountingReview $r): array => $r->source['case_ids'] ?? [])->unique()->all();
        $visible = BillingCase::query()->whereIn('id', $ids)
            ->whereIn('trip_phase_id', $this->scope->tripPhases($actor)->select('trip_phases.id'))
            ->with(['currentFinal', 'currentInitial', 'currentCode', 'codes'])->get()->keyBy('id');
        $result = [];
        foreach ($reviews as $review) {
            $members = $review->source['case_ids'] ?? [];
            if ($members === [] || ! collect($members)->every(fn ($id): bool => $visible->has((int) $id))) {
                continue;
            }
            foreach ($members as $id) {
                $case = $visible->get((int) $id);
                if ($review->period_year === $this->period->year($case, $case->currentFinal ?? $case->currentInitial)) {
                    $result[(int) $id] = $review;
                }
            }
        }

        return $result;
    }

    private function state(?AccountingReview $review): string
    {
        return $review?->difference === null ? 'pending' : (D::compare($review->difference, '0') === 0 ? 'reconciled' : 'flagged');
    }

    public function states(User $actor): array
    {
        return array_map(fn (AccountingReview $review): string => $this->state($review), $this->reviews($actor));
    }

    private function filter($query, ?string $status, array $reviews, string $column): void
    {
        abort_unless($status === null || in_array($status, ['pending', 'reconciled', 'flagged'], true), 422);
        if ($status === null) {
            return;
        }
        $known = [];
        foreach ($reviews as $id => $review) {
            if ($this->state($review) !== 'pending') {
                $known[] = $id;
            }
        }
        if ($status === 'pending') {
            $query->where(fn ($q) => $q->whereNull($column)->orWhereNotIn($column, $known));
        } else {
            $query->whereIn($column, array_keys(array_filter($reviews, fn (AccountingReview $r): bool => $this->state($r) === $status)));
        }
    }

    public function invoices(User $actor, ?string $status)
    {
        $reviews = $this->reviews($actor);
        $query = $this->scope->constrain(Invoice::query(), $actor, 'invoices.trip_phase_id')
            ->with(['tripPhase.project', 'tripPhase.team', 'contactPerson']);
        $this->filter($query, $status, $reviews, 'billing_case_id');

        return $query->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(25)->withQueryString()
            ->through(function (Invoice $invoice) use ($actor, $reviews): array {
                $review = $reviews[$invoice->billing_case_id] ?? null;

                return array_replace($invoice->toArray(), ['accounting_status' => $this->state($review),
                    'accounting_period_year' => $review?->period_year,
                    'urls' => ['review' => $review && $actor->hasPermission('accounting.manage') ? route('accounting-reviews.edit', $review) : null]]);
            });
    }

    public function summary(User $actor, ?string $status): array
    {
        $reviews = $this->reviews($actor);
        $query = BillingCase::query()->whereIn('trip_phase_id', $this->scope->tripPhases($actor)->select('trip_phases.id'))
            ->where(fn ($q) => $q->whereNotNull('current_initial_id')->orWhereNotNull('current_final_id'));
        $this->filter($query, $status, $reviews, 'billing_cases.id');
        $result = ['count' => 0, 'pending' => 0, 'reconciled' => 0, 'flagged' => 0, 'total_dr' => '0.00', 'total_wodr' => '0.00',
            'grand_total' => '0.00', 'balance_conciliation' => '0.00', 'partial' => false, 'count_basis' => 'expedientes'];
        $incomplete = false;
        $query->with('currentFinal')->chunkById(500, function ($cases) use (&$result, &$incomplete, $reviews): void {
            foreach ($cases as $case) {
                $result['count']++;
                $result[$this->state($reviews[$case->id] ?? null)]++;
                $final = $case->currentFinal;
                if (! $final || ! in_array($final->status, ['approved', 'sent', 'paid'], true)) {
                    $incomplete = true;

                    continue;
                }
                foreach (['total_dr', 'total_wodr', 'grand_total', 'balance_conciliation'] as $field) {
                    $result[$field] = D::money(D::add($result[$field], (string) $final->{$field}));
                }
            }
        });
        if ($incomplete) {
            foreach (['total_dr', 'total_wodr', 'grand_total', 'balance_conciliation'] as $field) {
                $result[$field] = null;
            }
        }

        return $result;
    }
}
