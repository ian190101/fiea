<?php
declare(strict_types=1);
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
final class UpdateBillingCaseSettingsRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('budgets.manage') ?? false; }
    public function rules(): array { return [
        'version'=>['required','integer','min:1'],
        'bank_fee_initial'=>['sometimes','string','regex:/^\d{1,15}\.\d{2}$/'],
        'bank_fee_final'=>['sometimes','string','regex:/^\d{1,15}\.\d{2}$/'],
        'final_contingency'=>['nullable','string','regex:/^\d{1,15}\.\d{2}$/'],
        'reason'=>['nullable','string','max:2000'],
        'total_dr'=>['prohibited'],'total_wodr'=>['prohibited'],
        'grand_total'=>['prohibited'],'balance_conciliation'=>['prohibited'],
    ]; }
}
