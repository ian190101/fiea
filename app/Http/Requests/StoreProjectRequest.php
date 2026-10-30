<?php
declare(strict_types=1);
namespace App\Http\Requests;

use App\Models\Community;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool)($this->user()?->scope_all && $this->user()?->hasPermission('projects.manage'));
    }
    public function rules(): array
    {
        return [
            'country_id'=>['required','integer',Rule::exists('countries','id')],
            'community_id'=>['nullable','integer',Rule::exists('communities','id')],
            'code'=>['required','string','max:80',Rule::unique('projects','code')],
            'name'=>['required','string','max:160'],
            'started_on'=>['nullable','date_format:Y-m-d'],
            'closed_on'=>['nullable','date_format:Y-m-d','after_or_equal:started_on'],
            'description'=>['nullable','string','max:255'],
        ];
    }
    public function after(): array
    {
        return [function ($validator): void {
            if (!$this->filled('community_id')) return;
            $community=Community::query()->find($this->integer('community_id'));
            if ($community && (int)$community->country_id!==$this->integer('country_id'))
                $validator->errors()->add('community_id',
                    'La comunidad seleccionada no pertenece al país indicado.');
        }];
    }
}
