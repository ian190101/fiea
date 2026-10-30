<?php
declare(strict_types=1);
namespace App\Services\Finance;

use App\Models\ActualExpense;
use App\Models\BillingCase;
use App\Models\EstimatedExpense;
use App\Models\ExpenseCategory;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\Security\ScopeService;
use App\Support\Decimal as D;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class ExpenseMutationService
{
    public function __construct(private readonly ScopeService $scope) {}

    public function save(User $actor, int $phaseId, ?int $expenseId, array $validated, int $expectedVersion): Model
    {
        return DB::transaction(function () use ($actor, $phaseId, $expenseId, $validated, $expectedVersion): Model {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $phase = TripPhase::query()->lockForUpdate()->findOrFail($phaseId);
            BillingCase::query()->where('trip_phase_id', $phaseId)->orderBy('id')->lockForUpdate()->get();
            $actual = array_key_exists('final_unit_cost', $validated);
            $this->scope->authorize($actor, $actual ? 'actual_expenses.manage' : 'budgets.manage', $phaseId);
            abort_unless((int) $phase->version === $expectedVersion, 409);
            abort_unless((int) $validated['trip_phase_id'] === $phaseId, 422);
            abort_unless(!BillingCase::query()->where('trip_phase_id', $phaseId)->exists(), 409,
                'Este viaje usa la matriz financiera. Modifica sus líneas allí.');
            $class = $actual ? ActualExpense::class : EstimatedExpense::class;
            $expense = $expenseId === null ? new $class() : $class::query()->lockForUpdate()->findOrFail($expenseId);
            abort_unless(!$expense->exists || (int) $expense->trip_phase_id === $phaseId, 404);
            $cost = (string) $validated[$actual ? 'final_unit_cost' : 'initial_unit_cost'];
            $quantity = (string) $validated[$actual ? 'final_quantity' : 'initial_quantity'];
            $validated[$actual ? 'real_total' : 'estimated_total'] = D::money(D::mul($cost, $quantity));
            $validated['fund_type']=ExpenseCategory::query()->findOrFail($validated['expense_category_id'])->fund_type;
            if ($actual) {
                $validated['reported_by_id'] = $actor->id;
                $validated['reported_at'] = $expense->reported_at ?? now();
            }
            $expense->fill($validated)->save();
            $phase->forceFill(['version' => $phase->version + 1])->save();
            return $expense;
        }, 3);
    }

    public function delete(User $actor, int $phaseId, int $expenseId, bool $actual, int $expectedVersion): void
    {
        DB::transaction(function () use ($actor, $phaseId, $expenseId, $actual, $expectedVersion): void {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $phase = TripPhase::query()->lockForUpdate()->findOrFail($phaseId);
            BillingCase::query()->where('trip_phase_id', $phaseId)->orderBy('id')->lockForUpdate()->get();
            $this->scope->authorize($actor, $actual ? 'actual_expenses.manage' : 'budgets.manage', $phaseId);
            abort_unless((int) $phase->version === $expectedVersion, 409);
            abort_unless(!BillingCase::query()->where('trip_phase_id', $phaseId)->exists(), 409);
            $class = $actual ? ActualExpense::class : EstimatedExpense::class;
            $expense = $class::query()->where('trip_phase_id', $phaseId)->lockForUpdate()->findOrFail($expenseId);
            $expense->delete();
            $phase->forceFill(['version' => $phase->version + 1])->save();
        }, 3);
    }
}
