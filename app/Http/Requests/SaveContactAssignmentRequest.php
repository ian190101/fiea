<?php
declare(strict_types=1);
namespace App\Http\Requests;

use App\Models\ContactAssignment;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class SaveContactAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor=$this->user(); $current=$this->route('assignment');
        if (!$actor || !$actor->hasPermission('contacts.manage')) return false;
        if ($actor->scope_all) return true;
        $chapter=(int)($this->input('chapter_id') ?: Team::query()
            ->whereKey($this->input('team_id'))->value('chapter_id'));
        $granted=fn (int $id): bool => DB::table('user_chapter_assignments')
            ->where('user_id',$actor->id)->where('chapter_id',$id)->exists();
        return $granted($chapter) && (!$current || $granted((int)$current->chapter_id));
    }
    public function rules(): array
    {
        return [
            'contact_person_id'=>['required','integer',Rule::exists('contact_people','id')],
            'chapter_id'=>['nullable','integer',Rule::exists('chapters','id')],
            'team_id'=>['nullable','integer',Rule::exists('teams','id')],
            'role'=>['required','string','max:80'],
            'is_billing_contact'=>['required','boolean'],
            'is_email_recipient'=>['required','boolean'],
            'is_active'=>['required','boolean'],
        ];
    }
    public function after(): array
    {
        return [function ($validator): void {
            if (!$this->filled('chapter_id') && !$this->filled('team_id'))
                $validator->errors()->add('chapter_id','Selecciona un capítulo o un equipo.');
            if (!$this->filled('team_id') || !$this->filled('chapter_id')) return;
            $team=Team::query()->find($this->integer('team_id'));
            if ($team && (int)$team->chapter_id!==$this->integer('chapter_id'))
                $validator->errors()->add('team_id',
                    'El equipo seleccionado no pertenece al capítulo indicado.');
        }];
    }
}
