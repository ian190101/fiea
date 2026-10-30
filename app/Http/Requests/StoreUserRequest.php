<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\PasswordByteLength;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => Str::lower(trim($this->input('username')))]);
        }
    }

    public function authorize(): bool
    {
        return (bool) ($this->user()?->is_active && $this->user()?->scope_all && $this->user()?->hasPermission('superadmin.manage'));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:120', 'alpha_dash', Rule::unique('users', 'username_normalized')],
            'email' => ['nullable', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:255', new PasswordByteLength],
            'must_change_password' => ['required', 'boolean'],
            'theme_preference' => ['required', 'string', Rule::in(['light', 'dark', 'system'])],
            'is_active' => ['required', 'boolean'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')],
        ];
    }
}
