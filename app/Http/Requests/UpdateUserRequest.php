<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\PasswordByteLength;
use Illuminate\Validation\Rule;

final class UpdateUserRequest extends StoreUserRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $user = $this->route('user');
        $rules['username'] = ['required', 'string', 'max:120', 'alpha_dash',
            Rule::unique('users', 'username_normalized')->ignore($user)];
        $rules['email'] = ['nullable', 'email:rfc', 'max:255',
            Rule::unique('users', 'email')->ignore($user)];
        $rules['password'] = ['nullable', 'string', 'min:8', 'max:255', new PasswordByteLength];

        return $rules;
    }
}
