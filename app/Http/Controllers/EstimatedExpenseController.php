<?php

namespace App\Http\Controllers;

use App\Models\EstimatedExpense;
use App\Models\ExpenseCategory;
use App\Models\TripPhase;
use App\Services\Security\ScopeService;
use App\Services\Finance\ExpenseMutationService;
use App\Support\Decimal as D;
use App\Http\Requests\StoreEstimatedExpenseRequest;
use App\Http\Requests\UpdateEstimatedExpenseRequest;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EstimatedExpenseController extends Controller
{
    public function index(ScopeService $scope): Response
    {
        $actor = request()->user();
        return Inertia::render('EstimatedExpenses/Index', [
            // Los datos esenciales llegan juntos: evita formularios vacíos y una segunda navegación.
            'estimatedExpenses' => fn () => $scope->constrain(EstimatedExpense::query(), $actor, 'estimated_expenses.trip_phase_id')
                ->select([
                    'id',
                    'trip_phase_id',
                    'expense_category_id',
                    'description',
                    'unit',
                    'initial_unit_cost',
                    'initial_quantity',
                    'estimated_total',
                    'fund_type',
                ])
                ->with([
                    'tripPhase:id,project_id,team_id,phase,starts_on,ends_on,status,version',
                    'tripPhase.project:id,code,name',
                    'tripPhase.team:id,name',
                    'expenseCategory:id,name,fund_type,applies_service_fee,applies_contingency,service_fee_percentage',
                ])
                ->orderBy('fund_type')
                ->orderBy('description')
                ->orderBy('id')->cursorPaginate(25),
            'tripPhases' => fn () => $scope->tripPhases($actor)
                ->with(['project:id,code,name', 'team:id,name', 'draftPdfFile:id,original_name'])
                ->orderByDesc('starts_on')
                ->orderByDesc('id')->limit(25)
                ->get(['id', 'project_id', 'team_id', 'phase', 'starts_on', 'ends_on', 'status', 'version', 'draft_pdf_file_id']),
            'expenseCategories' => fn () => ExpenseCategory::query()
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'fund_type',
                    'applies_service_fee',
                    'applies_contingency',
                    'service_fee_percentage',
                ]),
        ]);
    }

    public function store(StoreEstimatedExpenseRequest $request): RedirectResponse
    {
        $data = $request->validated();
        app(ExpenseMutationService::class)->save($request->user(), (int)$data['trip_phase_id'],
            null, $data, (int)$data['version']);

        return back()->with('success', 'Gasto estimado creado correctamente.');
    }

    public function update(UpdateEstimatedExpenseRequest $request, EstimatedExpense $estimatedExpense): RedirectResponse
    {
        $data = $request->validated();
        app(ExpenseMutationService::class)->save($request->user(), (int) $estimatedExpense->trip_phase_id,
            (int) $estimatedExpense->id, $data, (int) $data['version']);

        return back()->with('success', 'Gasto estimado actualizado correctamente.');
    }

    public function destroy(EstimatedExpense $estimatedExpense): RedirectResponse
    {
        $version = request()->validate(['version' => ['required', 'integer', 'min:1']]);
        try {
            app(ExpenseMutationService::class)->delete(request()->user(), (int) $estimatedExpense->trip_phase_id,
                (int) $estimatedExpense->id, false, (int) $version['version']);
        } catch (QueryException) {
            return back()->withErrors([
                'estimatedExpense' => 'No se puede eliminar porque esta relacionado con gastos reales.',
            ]);
        }

        return back()->with('success', 'Gasto estimado eliminado correctamente.');
    }

}
