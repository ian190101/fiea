<?php
declare(strict_types=1);
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool)($this->user()?->scope_all && $this->user()?->hasPermission('superadmin.manage'));
    }
    public function rules(): array
    {
        return [
            'name'=>['required','string','max:120'],
            'code'=>['nullable','string','max:80','alpha_dash',Rule::unique('roles','code')],
            'description'=>['nullable','string','max:500'],
            'permission_ids'=>['array'],
            'permission_ids.*'=>['integer',Rule::exists('permissions','id')],
        ];
    }
}
