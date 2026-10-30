<?php
declare(strict_types=1);
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTripPhaseRequest extends FormRequest
{
    private const PHASES=['Initial Visit','Implementation Trip','Follow-up Trip','Closeout'];
    private const STATUSES=['draft','scheduled','in_progress','completed','cancelled'];
    public function authorize(): bool
    {
        return (bool)$this->user()?->hasPermission('trip_phases.manage');
    }
    public function rules(): array
    {
        return [
            'project_id'=>['required','integer',Rule::exists('projects','id')],
            'team_id'=>['required','integer',Rule::exists('teams','id')],
            'assigned_technician_id'=>['nullable','integer',Rule::exists('users','id')],
            'phase'=>['required','string','max:40',Rule::in(self::PHASES)],
            'starts_on'=>['required','date_format:Y-m-d'],
            'ends_on'=>['required','date_format:Y-m-d','after_or_equal:starts_on'],
            'volunteer_count'=>['required','integer','min:0','max:65535'],
            'staff_count'=>['required','integer','min:0','max:65535'],
            'status'=>['required','string',Rule::in(self::STATUSES)],
        ];
    }
}
