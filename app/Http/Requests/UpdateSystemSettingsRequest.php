<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateSystemSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('settings.manage');
    }

    public function rules(): array
    {
        return [
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'lock_final_invoice_by_default' => ['prohibited'],
            'accounting_can_edit_summary' => ['prohibited'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp',
                'mimetypes:image/png,image/jpeg,image/webp', 'max:4096'],
        ];
    }
}
