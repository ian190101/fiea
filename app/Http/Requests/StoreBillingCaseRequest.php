<?php
declare(strict_types=1);
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
final class StoreBillingCaseRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('budgets.manage') ?? false; }
    public function rules(): array { return [
        'invoice_type'=>['required',Rule::in(['IC','MAT'])],
        'billing_mode'=>['required',Rule::in(['standard','final_only'])],
        'metadata'=>['nullable','array'],
    ]; }
}
