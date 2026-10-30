<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ActualExpense;
use App\Models\BudgetLine;
use App\Services\Security\ScopeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        if (! $actor || ! $actor->hasPermission('receipts.manage')) {
            return false;
        }
        if ($this->filled('budget_line_id')) {
            return BudgetLine::query()->whereKey($this->input('budget_line_id'))
                ->whereHas('billingCase', fn ($q) => $q->whereIn('trip_phase_id', app(ScopeService::class)->tripPhases($actor)->select('trip_phases.id')))->exists();
        }

        return app(ScopeService::class)->constrain(ActualExpense::query(), $actor, 'actual_expenses.trip_phase_id')
            ->whereKey($this->input('actual_expense_id'))->exists();
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'actual_expense_id' => ['nullable', 'required_without:budget_line_id', Rule::prohibitedIf($this->filled('budget_line_id')), 'integer', 'exists:actual_expenses,id'],
            'budget_line_id' => ['nullable', 'required_without:actual_expense_id', Rule::prohibitedIf($this->filled('actual_expense_id')), 'integer', 'exists:budget_lines,id'],
            'receipt_number' => ['nullable', 'string', 'max:120'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'amount' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/D'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp',
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp'],
        ];
    }
}
