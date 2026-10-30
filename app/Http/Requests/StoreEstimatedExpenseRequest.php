<?php
declare(strict_types=1);
namespace App\Http\Requests;

use App\Services\Security\ScopeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEstimatedExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor=$this->user();
        return $actor && $actor->hasPermission('budgets.manage')
            && app(ScopeService::class)->permits($actor,(int)$this->input('trip_phase_id'));
    }
    public function rules(): array
    {
        return [
            'version'=>['required','integer','min:1'],
            'trip_phase_id'=>['required','integer',Rule::exists('trip_phases','id')],
            'expense_category_id'=>['required','integer',Rule::exists('expense_categories','id')],
            'description'=>['required','string','max:255'],
            'unit'=>['nullable','string','max:80'],
            'initial_unit_cost'=>['required','string','regex:/^(?:0|[1-9][0-9]{0,10})(?:\.[0-9]{1,4})?$/D'],
            'initial_quantity'=>['required','string','regex:/^(?:0|[1-9][0-9]{0,10})(?:\.[0-9]{1,4})?$/D'],
        ];
    }
}
