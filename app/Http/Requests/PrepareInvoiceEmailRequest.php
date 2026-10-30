<?php
declare(strict_types=1);
namespace App\Http\Requests;

use App\Models\Invoice;
use App\Services\Security\ScopeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PrepareInvoiceEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        $invoice = $this->route('invoice');
        return $invoice instanceof Invoice && $this->user()?->hasPermission('invoice_emails.manage')
            && app(ScopeService::class)->permits($this->user(), (int) $invoice->trip_phase_id);
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'recipients' => ['required', 'array', 'min:1', 'max:30'],
            'recipients.*.contact_person_id' => ['nullable', 'integer', Rule::exists('contact_people', 'id')],
            'recipients.*.email' => ['required', 'email:rfc', 'max:255'],
            'recipients.*.recipient_type' => ['required', 'string', Rule::in(['to', 'cc', 'bcc'])],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if (!collect($this->input('recipients', []))->contains(
                fn ($row) => ($row['recipient_type'] ?? null) === 'to')) {
                $validator->errors()->add('recipients', 'Debe existir al menos un destinatario principal TO.');
            }
        }];
    }
}
