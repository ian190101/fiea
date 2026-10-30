<?php
declare(strict_types=1);
namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class UpdateProjectRequest extends StoreProjectRequest
{
    public function authorize(): bool
    {
        $project=$this->route('project');
        $actor=$this->user();
        return $project instanceof Project && $actor && $actor->hasPermission('projects.manage')
            && ($actor->scope_all || DB::table('user_project_assignments')
                ->where('user_id',$actor->id)->where('project_id',$project->id)->exists());
    }
    public function rules(): array
    {
        $rules=parent::rules();
        $rules['code']=['required','string','max:80',
            Rule::unique('projects','code')->ignore($this->route('project'))];
        return $rules;
    }
}
