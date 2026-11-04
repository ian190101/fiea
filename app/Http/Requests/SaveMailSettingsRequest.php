<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveMailSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('settings.manage');
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('app_password')) {
            $this->merge(['app_password' => preg_replace('/\s+/', '', (string) $this->input('app_password'))]);
        }
    }

    public function rules(): array
    {
        return ['account' => ['required', 'email:rfc', 'max:255'], 'from_name' => ['required', 'string', 'max:100', 'not_regex:/[\r\n]/'],
            'app_password' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{16}$/'],
            'enabled' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:0']];
    }
}
