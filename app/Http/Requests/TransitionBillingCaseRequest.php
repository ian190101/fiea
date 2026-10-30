<?php
declare(strict_types=1);
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
final class TransitionBillingCaseRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('billing_cases.transition') ?? false; }
    public function rules(): array { return [
        'version'=>['required','integer','min:1'],
        'target'=>['required',Rule::in(['POR GASTAR','FINAL PEND','CERRADO','ANULADO'])],
        'reason'=>['required','string','min:10','max:2000'],
    ]; }
}
