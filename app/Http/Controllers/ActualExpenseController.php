<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreActualExpenseRequest;
use App\Http\Requests\UpdateActualExpenseRequest;
use App\Models\ActualExpense;
use App\Models\EstimatedExpense;
use App\Models\ExpenseCategory;
use App\Queries\ExpenseSummaryQuery;
use App\Services\Finance\ExpenseMutationService;
use App\Services\Security\ScopeService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ActualExpenseController extends Controller
{
    public function index(ScopeService $scope): Response
    {
        $actor = request()->user();
        request()->validate(['trip_phase_id' => ['nullable', 'integer', 'min:1']]);

        return Inertia::render('ActualExpenses/Index', [
            'summary' => Inertia::defer(fn () => app(ExpenseSummaryQuery::class)
                ->for($actor, request()->integer('trip_phase_id') ?: null), 'actual-expenses'),
            'actualExpenses' => Inertia::defer(fn () => $scope->constrain(ActualExpense::query(), $actor, 'actual_expenses.trip_phase_id')
                ->when(request()->integer('trip_phase_id'), fn ($q, $id) => $q->where('trip_phase_id', $id))
                ->select([
                    'id',
                    'trip_phase_id',
                    'estimated_expense_id',
                    'expense_category_id',
                    'reported_by_id',
                    'description',
                    'unit',
                    'final_unit_cost',
                    'final_quantity',
                    'real_total',
                    'receipt_number',
                    'fund_type',
                    'reported_at',
                ])
                ->with([
                    'tripPhase:id,project_id,team_id,phase,starts_on,ends_on,status,version',
                    'tripPhase.project:id,code,name',
                    'tripPhase.team:id,name',
                    'estimatedExpense:id,description,estimated_total',
                    'expenseCategory:id,name,fund_type',
                    'reportedBy:id,name,username',
                ])
                ->withCount('receipts')
                ->orderByDesc('reported_at')
                ->orderByDesc('id')->cursorPaginate(25), 'actual-expenses'),
            'tripPhases' => Inertia::defer(fn () => $scope->tripPhases($actor)
                ->with(['project:id,code,name', 'team:id,name'])
                ->orderByDesc('starts_on')
                ->limit(25)->get(['id', 'project_id', 'team_id', 'phase', 'starts_on', 'ends_on', 'status', 'version']), 'actual-expenses'),
            'estimatedExpenses' => Inertia::defer(fn () => $scope->constrain(EstimatedExpense::query(), $actor, 'estimated_expenses.trip_phase_id')
                ->with(['expenseCategory:id,name,fund_type'])
                ->orderBy('description')
                ->limit(25)->get([
                    'id',
                    'trip_phase_id',
                    'expense_category_id',
                    'description',
                    'unit',
                    'initial_unit_cost',
                    'initial_quantity',
                    'estimated_total',
                    'fund_type',
                ]), 'actual-expenses'),
            'expenseCategories' => Inertia::defer(fn () => ExpenseCategory::query()
                ->orderBy('name')
                ->get(['id', 'name', 'fund_type']), 'actual-expenses'),
        ]);
    }

    public function store(StoreActualExpenseRequest $request): RedirectResponse
    {
        $data = $request->validated();
        app(ExpenseMutationService::class)->save($request->user(), (int) $data['trip_phase_id'],
            null, $data, (int) $data['version']);

        return back()->with('success', 'Gasto real creado correctamente.');
    }

    public function update(UpdateActualExpenseRequest $request, ActualExpense $actualExpense): RedirectResponse
    {
        $data = $request->validated();
        app(ExpenseMutationService::class)->save($request->user(), (int) $actualExpense->trip_phase_id,
            (int) $actualExpense->id, $data, (int) $data['version']);

        return back()->with('success', 'Gasto real actualizado correctamente.');
    }

    public function destroy(ActualExpense $actualExpense): RedirectResponse
    {
        $version = request()->validate(['version' => ['required', 'integer', 'min:1']]);
        try {
            app(ExpenseMutationService::class)->delete(request()->user(), (int) $actualExpense->trip_phase_id,
                (int) $actualExpense->id, true, (int) $version['version']);
        } catch (QueryException) {
            return back()->withErrors([
                'actualExpense' => 'No se puede eliminar porque tiene comprobantes relacionados.',
            ]);
        }

        return back()->with('success', 'Gasto real eliminado correctamente.');
    }
}
