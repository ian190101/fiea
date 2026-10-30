<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\ActualExpense;
use App\Models\BudgetLine;
use App\Models\EstimatedExpense;
use App\Models\User;
use App\Services\Security\ScopeService;
use App\Support\Decimal as D;

final class ExpenseSummaryQuery
{
    public function for(User $actor, ?int $phaseId): array
    {
        $scope = app(ScopeService::class);
        $phases = $scope->tripPhases($actor)->when($phaseId, fn ($q) => $q->whereKey($phaseId))->select('trip_phases.id');
        $totals = [];
        foreach (['initial' => EstimatedExpense::class, 'final' => ActualExpense::class] as $stage => $class) {
            $legacyColumn = $stage === 'initial' ? 'legacy_estimated_expense_id' : 'legacy_actual_expense_id';
            $field = $stage === 'initial' ? 'estimated_total' : 'real_total';
            $total = '0';
            $class::query()->whereIn('trip_phase_id', clone $phases)
                ->whereNotIn('id', BudgetLine::query()->whereNotNull($legacyColumn)->select($legacyColumn))
                ->select(['id', $field])->chunkById(500, function ($rows) use (&$total, $field): void {
                    foreach ($rows as $row) {
                        $total = D::add($total, (string) $row->{$field});
                    }
                });
            $pending = false;
            BudgetLine::query()->whereHas('billingCase', fn ($q) => $q->whereIn('trip_phase_id', clone $phases))
                ->with(['billingCase.tripPhase', 'versions' => fn ($q) => $q->where('stage', $stage), 'versions.cells'])
                ->chunkById(250, function ($lines) use (&$total, &$pending): void {
                    foreach ($lines as $line) {
                        $version = $line->versions->first();
                        if (! $version || ! $version->is_captured) {
                            $pending = true;

                            continue;
                        }
                        $quantity = $version->quantity;
                        if ($quantity === null) {
                            $quantity = D::sum($version->cells
                                ->filter(fn ($c) => $c->spent_on->betweenIncluded($line->billingCase->tripPhase->starts_on, $line->billingCase->tripPhase->ends_on))
                                ->pluck('quantity')->all());
                        }
                        $total = D::add($total, D::mul($version->unit_cost, $quantity));
                    }
                });
            $totals[$stage] = $pending ? null : D::money($total);
        }

        return ['trip_phase_id' => $phaseId, 'estimated' => $totals['initial'], 'real' => $totals['final'],
            'variance' => $totals['initial'] === null || $totals['final'] === null ? null : D::money(D::sub($totals['final'], $totals['initial']))];
    }
}
