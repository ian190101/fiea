<?php
declare(strict_types=1);
namespace App\Http\Requests;

use App\Models\EstimatedExpense;
use App\Services\Security\ScopeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreActualExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor=$this->user();
        return $actor && $actor->hasPermission('actual_expenses.manage')
            && app(ScopeService::class)->permits($actor,(int)$this->input('trip_phase_id'));
    }
    public function rules(): array
    {
        return [
            'version'=>['required','integer','min:1'],
            'trip_phase_id'=>['required','integer',Rule::exists('trip_phases','id')],
            'estimated_expense_id'=>['nullable','integer',Rule::exists('estimated_expenses','id')],
            'expense_category_id'=>['required','integer',Rule::exists('expense_categories','id')],
            'description'=>['required','string','max:255'],
            'unit'=>['nullable','string','max:80'],
            'final_unit_cost'=>['required','string','regex:/^(?:0|[1-9][0-9]{0,10})(?:\.[0-9]{1,4})?$/D'],
            'final_quantity'=>['required','string','regex:/^(?:0|[1-9][0-9]{0,10})(?:\.[0-9]{1,4})?$/D'],
            'receipt_number'=>['nullable','string','max:120'],
        ];
    }
    public function after(): array
    {
        return [function ($validator): void {
            if (!$this->filled('estimated_expense_id')) return;
            $estimate=app(ScopeService::class)->constrain(EstimatedExpense::query(),$this->user(),
                'estimated_expenses.trip_phase_id')->find($this->integer('estimated_expense_id'));
            if (!$estimate || (int)$estimate->trip_phase_id!==$this->integer('trip_phase_id'))
                $validator->errors()->add('estimated_expense_id',
                    'La línea estimada no pertenece a la fase seleccionada.');
        }];
    }
}
