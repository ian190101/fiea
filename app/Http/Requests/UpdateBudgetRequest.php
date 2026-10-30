<?php
declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\Financial\UpdateBudgetData;
use App\Services\Security\ScopeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $phase = $this->route('tripPhase');
        $permission = $this->input('stage') === 'final' ? 'actual_expenses.manage' : 'budgets.manage';
        return $this->user()?->hasPermission($permission) === true
            && $phase !== null
            && app(ScopeService::class)->permits($this->user(), (int) $phase->id);
    }

    public function rules(): array
    {
        $decimal = ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,10})(?:\.[0-9]{1,4})?$/D'];
        return [
            'invoice_type' => ['required', Rule::in(['IC', 'MAT'])],
            'stage' => ['required', Rule::in(['initial', 'final'])],
            'version' => ['required', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'max:500'],
            'lines.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.unit_cost' => $decimal,
            'lines.*.quantity' => ['nullable', 'string', 'regex:/^(?:0|[1-9][0-9]{0,10})(?:\.[0-9]{1,4})?$/D'],
            'lines.*.receipt_number' => ['nullable', 'string', 'max:255'],
            'lines.*.cells' => ['present', 'array', 'max:366'],
            'lines.*.cells.*.date' => ['required', 'date_format:Y-m-d'],
            'lines.*.cells.*.quantity' => $decimal,
            'lines.*.cells.*.receipt_number' => ['nullable', 'string', 'max:255'],
            'total' => ['prohibited'], 'fund_type' => ['prohibited'],
            'bucket' => ['prohibited'], 'arranged_eligible' => ['prohibited'],
            'status' => ['prohibited'], 'billing_case_id' => ['prohibited'],
            'approved_by_id' => ['prohibited'],
            'lines.*.total' => ['prohibited'], 'lines.*.bucket' => ['prohibited'],
            'lines.*.arranged_eligible' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->input('lines', []) as $index => $line) {
                if (!is_array($line)) continue;
                $cells = $line['cells'] ?? [];
                $dates = array_column(is_array($cells) ? $cells : [], 'date');
                if (count($dates) !== count(array_unique($dates))) {
                    $validator->errors()->add("lines.$index.cells", 'Hay fechas duplicadas.');
                }
                if ($this->input('invoice_type') === 'MAT' && (!isset($line['quantity']) || $cells !== [])) {
                    $validator->errors()->add("lines.$index.quantity", 'MAT requiere cantidad agregada y ninguna celda.');
                }
                if ($this->input('invoice_type') === 'IC' && ($line['quantity'] ?? null) !== null) {
                    $validator->errors()->add("lines.$index.quantity", 'IC usa cantidades diarias.');
                }
            }
        });
    }

    public function data($key = null, $default = null): UpdateBudgetData
    {
        abort_unless($key === null && $default === null, 422);
        $v = $this->validated();
        return new UpdateBudgetData($v['invoice_type'], $v['stage'], (int) $v['version'], $v['lines']);
    }
}
