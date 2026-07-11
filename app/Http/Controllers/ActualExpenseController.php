<?php

namespace App\Http\Controllers;

use App\Models\ActualExpense;
use App\Models\EstimatedExpense;
use App\Models\ExpenseCategory;
use App\Models\TripPhase;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ActualExpenseController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('ActualExpenses/Index', [
            'actualExpenses' => Inertia::defer(fn () => ActualExpense::query()
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
                    'tripPhase:id,project_id,team_id,phase,starts_on,ends_on,status',
                    'tripPhase.project:id,code,name',
                    'tripPhase.team:id,name',
                    'estimatedExpense:id,description,estimated_total',
                    'expenseCategory:id,name,fund_type',
                    'reportedBy:id,name,username',
                ])
                ->withCount('receipts')
                ->orderByDesc('reported_at')
                ->get(), 'actual-expenses'),
            'tripPhases' => Inertia::defer(fn () => TripPhase::query()
                ->with(['project:id,code,name', 'team:id,name'])
                ->orderByDesc('starts_on')
                ->get(['id', 'project_id', 'team_id', 'phase', 'starts_on', 'ends_on', 'status']), 'actual-expenses'),
            'estimatedExpenses' => Inertia::defer(fn () => EstimatedExpense::query()
                ->with(['expenseCategory:id,name,fund_type'])
                ->orderBy('description')
                ->get([
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

    public function store(Request $request): RedirectResponse
    {
        ActualExpense::query()->create($this->validatedData($request));

        return back()->with('success', 'Gasto real creado correctamente.');
    }

    public function update(Request $request, ActualExpense $actualExpense): RedirectResponse
    {
        $actualExpense->fill($this->validatedData($request, $actualExpense))->save();

        return back()->with('success', 'Gasto real actualizado correctamente.');
    }

    public function destroy(ActualExpense $actualExpense): RedirectResponse
    {
        try {
            $actualExpense->delete();
        } catch (QueryException) {
            return back()->withErrors([
                'actualExpense' => 'No se puede eliminar porque tiene comprobantes relacionados.',
            ]);
        }

        return back()->with('success', 'Gasto real eliminado correctamente.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, ?ActualExpense $actualExpense = null): array
    {
        $validator = Validator::make($request->all(), [
            'trip_phase_id' => ['required', 'integer', Rule::exists('trip_phases', 'id')],
            'estimated_expense_id' => ['nullable', 'integer', Rule::exists('estimated_expenses', 'id')],
            'expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')],
            'description' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:80'],
            'final_unit_cost' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'final_quantity' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'receipt_number' => ['nullable', 'string', 'max:120'],
        ]);

        $validator->after(function ($validator) use ($request) {
            if (!$request->filled('estimated_expense_id')) {
                return;
            }

            $estimatedExpense = EstimatedExpense::query()->find($request->integer('estimated_expense_id'));
            if ($estimatedExpense && $estimatedExpense->trip_phase_id !== $request->integer('trip_phase_id')) {
                $validator->errors()->add('estimated_expense_id', 'La linea estimada no pertenece a la fase seleccionada.');
            }
        });

        $data = $validator->validate();
        $category = ExpenseCategory::query()->findOrFail($data['expense_category_id']);

        $data['fund_type'] = $category->fund_type;
        $data['real_total'] = round((float) $data['final_unit_cost'] * (float) $data['final_quantity'], 2);
        $data['reported_by_id'] = $actualExpense?->reported_by_id ?? $request->user()?->id;
        $data['reported_at'] = $actualExpense?->reported_at ?? now();

        return $data;
    }
}
