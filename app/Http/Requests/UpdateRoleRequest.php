<?php
declare(strict_types=1);
namespace App\Http\Requests;

final class UpdateRoleRequest extends StoreRoleRequest
{
    public function rules(): array
    {
        $rules=parent::rules();
        unset($rules['code']);
        return $rules;
    }
}
