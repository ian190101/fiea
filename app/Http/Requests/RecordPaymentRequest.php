<?php
declare(strict_types=1);
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('payments.manage') ?? false; }
    public function rules(): array
    {
        return [
            'version' => ['required','integer','min:1'],
            'invoice_id' => ['nullable','integer','exists:invoices,id'],
            'leg' => ['required',Rule::in(['chapter_to_eia','eia_to_fiea'])],
            'stage' => ['required',Rule::in(['initial','final'])],
            'direction' => ['required',Rule::in(['receipt','refund'])],
            'amount' => ['required','string','regex:/^\d{1,15}\.\d{2}$/'],
            'paid_on' => ['required','date_format:Y-m-d'],
            'method' => ['required',Rule::in(['CHECK','ACH','CREDIT_CARD','PAYPAL','TRANSFER','OTHER'])],
            'reference' => ['required_if:direction,refund','nullable','string','max:255'],
            'note' => ['nullable','string','max:2000'],
            'idempotency_key' => ['required','uuid'],
            'total_dr' => ['prohibited'], 'total_wodr' => ['prohibited'],
            'grand_total' => ['prohibited'], 'balance_conciliation' => ['prohibited'],
            'credit_balance' => ['prohibited'],
        ];
    }
}
