<?php

namespace App\Http\Controllers;

use App\Models\EstimatedExpense;
use App\Models\ExpenseCategory;
use App\Models\TripPhase;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EstimatedExpenseController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('EstimatedExpenses/Index', [
            'estimatedExpenses' => Inertia::defer(fn () => EstimatedExpense::query()
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
                    'tripPhase:id,project_id,team_id,phase,starts_on,ends_on,status',
                    'tripPhase.project:id,code,name',
                    'tripPhase.team:id,name',
                    'expenseCategory:id,name,fund_type,applies_service_fee,applies_contingency,service_fee_percentage',
                ])
                ->orderBy('fund_type')
                ->orderBy('description')
                ->get(), 'estimated-expenses'),
            'tripPhases' => Inertia::defer(fn () => TripPhase::query()
                ->with(['project:id,code,name', 'team:id,name', 'draftPdfFile:id,original_name,object_key'])
                ->orderByDesc('starts_on')
                ->get(['id', 'project_id', 'team_id', 'phase', 'starts_on', 'ends_on', 'status', 'draft_pdf_file_id']), 'estimated-expenses'),
            'expenseCategories' => Inertia::defer(fn () => ExpenseCategory::query()
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'fund_type',
                    'applies_service_fee',
                    'applies_contingency',
                    'service_fee_percentage',
                ]), 'estimated-expenses'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        EstimatedExpense::query()->create($this->validatedData($request));

        return back()->with('success', 'Gasto estimado creado correctamente.');
    }

    public function update(Request $request, EstimatedExpense $estimatedExpense): RedirectResponse
    {
        $estimatedExpense->fill($this->validatedData($request))->save();

        return back()->with('success', 'Gasto estimado actualizado correctamente.');
    }

    public function destroy(EstimatedExpense $estimatedExpense): RedirectResponse
    {
        try {
            $estimatedExpense->delete();
        } catch (QueryException) {
            return back()->withErrors([
                'estimatedExpense' => 'No se puede eliminar porque esta relacionado con gastos reales.',
            ]);
        }

        return back()->with('success', 'Gasto estimado eliminado correctamente.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'trip_phase_id' => ['required', 'integer', Rule::exists('trip_phases', 'id')],
            'expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')],
            'description' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:80'],
            'initial_unit_cost' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'initial_quantity' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
        ]);

        $category = ExpenseCategory::query()->findOrFail($data['expense_category_id']);
        $data['fund_type'] = $category->fund_type;
        $data['estimated_total'] = round((float) $data['initial_unit_cost'] * (float) $data['initial_quantity'], 2);

        return $data;
    }
}
