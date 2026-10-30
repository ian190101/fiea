<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateLiquidGlassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->is_active && $this->user()?->hasRole('superadmin'));
    }

    public function rules(): array
    {
        $keys = array_keys(config('liquid-glass.defaults'));
        $rules = ['profiles' => ['required', 'array:panel,sidebar,button']];
        foreach (['panel', 'sidebar', 'button'] as $profile) {
            $rules["profiles.{$profile}"] = ['required', 'array:'.implode(',', $keys)];
            foreach (config('liquid-glass.ranges') as $key => [$min, $max]) {
                $rules["profiles.{$profile}.{$key}"] = ['required', 'numeric', "between:{$min},{$max}"];
            }
            $rules["profiles.{$profile}.bevelMode"] = ['required', 'integer', 'in:0,1'];
            $rules["profiles.{$profile}.floating"] = ['required', 'boolean'];
            $rules["profiles.{$profile}.button"] = ['required', 'boolean'];
        }
        return $rules;
    }
}
