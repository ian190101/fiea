<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Receipt;
use App\Services\Receipts\ReceiptTarget;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $receipt = $this->route('receipt');
        $actor = $this->user();

        return $actor && $receipt instanceof Receipt && $actor->hasPermission('receipts.manage')
            && app(ReceiptTarget::class)->visible($actor)->whereKey($receipt->id)->exists();
    }

    public function rules(): array
    {
        $rules = ['version' => ['required', 'integer', 'min:1']];
        if ($this->isMethod('DELETE')) {
            return $rules;
        }

        return $rules + [
            'receipt_number' => ['nullable', 'string', 'max:120'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'amount' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/D'],
        ];
    }
}
