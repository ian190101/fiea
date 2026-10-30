<?php
declare(strict_types=1);

namespace App\Http\Requests;

final class VoidInvoiceRequest extends InvoiceVersionRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'reason' => ['required', 'string', 'min:10', 'max:2000']];
    }
}
