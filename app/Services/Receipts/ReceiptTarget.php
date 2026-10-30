<?php

declare(strict_types=1);

namespace App\Services\Receipts;

use App\Models\ActualExpense;
use App\Models\BudgetLine;
use App\Models\Receipt;
use App\Models\User;
use App\Services\Security\ScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ReceiptTarget
{
    public function resolve(array $data, bool $lock = false): Model
    {
        abort_unless(! empty($data['actual_expense_id']) xor ! empty($data['budget_line_id']), 422,
            'Selecciona exactamente un gasto o una línea Final.');
        $query = ! empty($data['budget_line_id']) ? BudgetLine::query() : ActualExpense::query();
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($data['budget_line_id'] ?? $data['actual_expense_id']);
    }

    public function phaseId(Model $target): int
    {
        return $target instanceof BudgetLine ? (int) $target->billingCase()->value('trip_phase_id') : (int) $target->trip_phase_id;
    }

    public function visible(User $actor): Builder
    {
        $scope = app(ScopeService::class);

        return Receipt::query()->where(fn (Builder $q) => $q
            ->whereHas('actualExpense', fn (Builder $e) => $scope->constrain($e, $actor, 'actual_expenses.trip_phase_id'))
            ->orWhereHas('budgetLine.billingCase', fn (Builder $c) => $c->whereIn('trip_phase_id', $scope->tripPhases($actor)->select('trip_phases.id'))));
    }
}
