<?php
declare(strict_types=1);
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
final class StoreBudgetLineRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('budgets.manage') ?? false; }
    public function rules(): array { return [
        'version'=>['required','integer','min:1'], 'catalog_key'=>['nullable','string','max:100'],
        'description_en'=>['required_without:catalog_key','nullable','string','max:255'],
        'description_es'=>['nullable','string','max:255'], 'unit'=>['required','string','max:40'],
        'section'=>['nullable','string','max:80'], 'bucket'=>['nullable',Rule::in(['dr','wodr'])],
        'arranged_eligible'=>['nullable','boolean'],
    ]; }
}
