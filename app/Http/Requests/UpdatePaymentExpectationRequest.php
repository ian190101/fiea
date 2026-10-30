<?php
declare(strict_types=1);
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
final class UpdatePaymentExpectationRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('payments.manage') ?? false; }
    public function rules(): array { return [
        'version'=>['required','integer','min:1'], 'stage'=>['required',Rule::in(['initial','final'])],
        'expected_amount'=>['required','string','regex:/^\d{1,15}\.\d{2}$/'],
        'basis_invoice_id'=>['nullable','integer','exists:invoices,id'],
        'reason'=>['required','string','min:10','max:2000'],
    ]; }
}
