<?php
declare(strict_types=1);
namespace App\Http\Requests;

use App\Data\Financial\CreateInvoiceData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('invoices.manage') ?? false;
    }
    public function rules(): array
    {
        return [
            'billing_case_id' => ['required', 'integer', 'min:1'],
            'stage' => ['required', Rule::in(['initial', 'final'])],
            'version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
            'contact_person_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['prohibited'], 'total_dr' => ['prohibited'],
            'total_wodr' => ['prohibited'], 'grand_total' => ['prohibited'],
            'balance_conciliation' => ['prohibited'], 'approved_by_id' => ['prohibited'],
            'locked_at' => ['prohibited'], 'created_by_id' => ['prohibited'],
        ];
    }
    public function data($key = null, $default = null): CreateInvoiceData
    {
        abort_unless($key === null && $default === null, 422);
        $v = $this->validated();
        return new CreateInvoiceData(
            (int) $v['billing_case_id'], $v['stage'], (int) $v['version'],
            $v['idempotency_key'], isset($v['contact_person_id']) ? (int) $v['contact_person_id'] : null
        );
    }
}
