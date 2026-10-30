<?php
declare(strict_types=1);
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool)$this->user()?->hasPermission('invoice_emails.manage');
    }
    public function rules(): array
    {
        return [
            'name'=>['required','string','max:120'],
            'invoice_type'=>['required','string',Rule::in(['IC','MAT'])],
            'stage'=>['required','string',Rule::in(['initial','final']),
                Rule::unique('email_templates','stage')
                    ->where('invoice_type',$this->input('invoice_type'))
                    ->ignore($this->route('template'))],
            'subject_template'=>['required','string','max:255'],
            'body_template'=>['required','string','max:5000'],
            'is_active'=>['required','boolean'],
        ];
    }
}
