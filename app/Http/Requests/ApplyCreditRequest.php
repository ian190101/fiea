<?php
declare(strict_types=1);
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
final class ApplyCreditRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('credits.manage') ?? false; }
    public function rules(): array { return [
        'version'=>['required','integer','min:1'],
        'amount'=>['required','string','regex:/^\d{1,15}\.\d{2}$/'],
        'idempotency_key'=>['required','uuid'],
        'credit_account_id'=>['prohibited'],'credit_balance'=>['prohibited'],
        'grand_total'=>['prohibited'],'balance_conciliation'=>['prohibited'],
    ]; }
}
