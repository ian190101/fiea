<?php
declare(strict_types=1);
namespace App\Http\Requests;

use App\Models\ContactAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class SaveContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor=$this->user(); $contact=$this->route('contact');
        if (!$actor || !$actor->hasPermission('contacts.manage')) return false;
        if (!$contact) return (bool)$actor->scope_all;
        if ($actor->scope_all) return true;
        $assignments=ContactAssignment::query()->where('contact_person_id',$contact->id);
        $visible=DB::table('user_chapter_assignments')->select('chapter_id')->where('user_id',$actor->id);
        return (clone $assignments)->exists() && !(clone $assignments)
            ->whereNotIn('chapter_id',$visible)->exists();
    }
    public function rules(): array
    {
        return [
            'full_name'=>['required','string','max:160'],
            'email'=>['nullable','email:rfc','max:160',Rule::unique('contact_people','email')
                ->ignore($this->route('contact'))],
            'phone'=>['nullable','string','max:60'],
            'physical_address'=>['nullable','string','max:255'],
        ];
    }
}
