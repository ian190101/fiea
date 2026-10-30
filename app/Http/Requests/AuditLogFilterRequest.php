<?php
declare(strict_types=1);
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AuditLogFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool)$this->user()?->hasPermission('audit_logs.view');
    }
    public function rules(): array
    {
        return [
            'module'=>['nullable','string','max:80'],
            'action'=>['nullable','string','max:120'],
            'user_id'=>['nullable','integer'],
        ];
    }
}
