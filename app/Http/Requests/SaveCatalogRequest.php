<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveCatalogRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_int($this->input('service_fee_percentage')) || is_float($this->input('service_fee_percentage'))) {
            $this->merge(['service_fee_percentage' => (string) $this->input('service_fee_percentage')]);
        }
    }

    public function authorize(): bool
    {
        return (bool) ($this->user()?->scope_all && $this->user()?->hasPermission('catalogs.manage'));
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return match ($this->route('catalog')) {
            'chapter-types' => [
                'name' => ['required', 'string', 'max:120', Rule::unique('chapter_types', 'name')->ignore($id)],
                'description' => ['nullable', 'string', 'max:255']],
            'countries' => [
                'name' => ['required', 'string', 'max:120', Rule::unique('countries', 'name')->ignore($id)],
                'description' => ['nullable', 'string', 'max:255']],
            'communities' => [
                'country_id' => ['required', 'integer', Rule::exists('countries', 'id')],
                'name' => ['required', 'string', 'max:120', Rule::unique('communities', 'name')
                    ->where('country_id', $this->integer('country_id'))->ignore($id)],
                'description' => ['nullable', 'string', 'max:255']],
            'universities' => [
                'name' => ['required', 'string', 'max:160', Rule::unique('universities', 'name')->ignore($id)]],
            'expense-categories' => [
                'name' => ['required', 'string', 'max:120', Rule::unique('expense_categories', 'name')->ignore($id)],
                'description' => ['nullable', 'string', 'max:255'],
                'fund_type' => ['required', Rule::in(['DR', 'WODR'])],
                'applies_service_fee' => ['required', 'boolean'],
                'applies_contingency' => ['required', 'boolean'],
                'service_fee_percentage' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]?|100)(?:\.[0-9]{1,4})?\z/D', 'numeric', 'between:0,100'],
            ],
            default => abort(404),
        };
    }
}
