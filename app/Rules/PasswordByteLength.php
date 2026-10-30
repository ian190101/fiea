<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PasswordByteLength implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && config('hashing.driver') === 'bcrypt' && strlen($value) > 72) {
            $fail('La contraseña admite hasta 72 bytes con el algoritmo configurado; los caracteres Unicode pueden ocupar varios bytes.');
        }
    }
}
