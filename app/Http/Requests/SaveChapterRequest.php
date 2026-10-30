<?php
declare(strict_types=1);
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class SaveChapterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor=$this->user(); $chapter=$this->route('chapter');
        if (!$actor || !$actor->hasPermission('chapters.manage')) return false;
        if (!$chapter) return (bool)$actor->scope_all;
        return $actor->scope_all || DB::table('user_chapter_assignments')
            ->where('user_id',$actor->id)->where('chapter_id',$chapter->id)->exists();
    }
    public function rules(): array
    {
        return [
            'chapter_type_id'=>['required','integer',Rule::exists('chapter_types','id')],
            'university_id'=>['nullable','integer',Rule::exists('universities','id')],
            'name'=>['required','string','max:160',
                Rule::unique('chapters','name')->ignore($this->route('chapter'))],
            'description'=>['nullable','string','max:255'],
        ];
    }
}
