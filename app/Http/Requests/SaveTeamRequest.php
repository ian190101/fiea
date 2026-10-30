<?php
declare(strict_types=1);
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class SaveTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor=$this->user(); $chapterId=(int)$this->input('chapter_id');
        $current=$this->route('team');
        if (!$actor || !$actor->hasPermission('chapters.manage')) return false;
        if ($actor->scope_all) return true;
        $granted=fn (int $id): bool => DB::table('user_chapter_assignments')
            ->where('user_id',$actor->id)->where('chapter_id',$id)->exists();
        return $granted($chapterId) && (!$current || $granted((int)$current->chapter_id));
    }
    public function rules(): array
    {
        return [
            'chapter_id'=>['required','integer',Rule::exists('chapters','id')],
            'name'=>['required','string','max:160',Rule::unique('teams','name')
                ->where('chapter_id',$this->integer('chapter_id'))->ignore($this->route('team'))],
            'description'=>['nullable','string','max:255'],
        ];
    }
}
