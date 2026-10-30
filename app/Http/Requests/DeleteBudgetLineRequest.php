<?php
declare(strict_types=1);
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
final class DeleteBudgetLineRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('budgets.manage') ?? false; }
    public function rules(): array { return ['version'=>['required','integer','min:1']]; }
}
