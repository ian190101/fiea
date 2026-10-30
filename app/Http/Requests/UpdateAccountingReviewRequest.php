<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAccountingReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'accounting_total' => ['nullable', 'string', 'regex:/^-?\d{1,13}\.\d{2}$/'],
            'note' => ['nullable', 'string', 'max:2000'],
            'operating_total' => ['prohibited'], 'difference' => ['prohibited'],
            'total_dr' => ['prohibited'], 'total_wodr' => ['prohibited'],
            'grand_total' => ['prohibited'], 'balance_conciliation' => ['prohibited'],
        ];
    }
}
