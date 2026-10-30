<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class UpdateUserScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->scope_all === true
            && Gate::forUser($this->user())->allows('scope.manage');
    }

    public function rules(): array
    {
        return [
            'project_ids' => ['present', 'array'],
            'project_ids.*' => ['integer', 'distinct', Rule::exists('projects', 'id')],
            'chapter_ids' => ['present', 'array'],
            'chapter_ids.*' => ['integer', 'distinct', Rule::exists('chapters', 'id')],
            'scope_all' => ['required', 'boolean'],
        ];
    }
}
