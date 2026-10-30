<?php
declare(strict_types=1);
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AlertFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool)$this->user()?->hasPermission('alerts.view');
    }
    public function rules(): array
    {
        return [
            'type'=>['nullable','in:invoice,accounting,receipt,email,trip'],
            'severity'=>['nullable','in:critical,warning,info'],
            'q'=>['nullable','string','max:80'],
        ];
    }
}
