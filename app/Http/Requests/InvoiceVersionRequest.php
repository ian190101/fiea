<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active === true;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['prohibited'],
            'total_dr' => ['prohibited'],
            'total_wodr' => ['prohibited'],
            'grand_total' => ['prohibited'],
        ];
    }
}
