<?php
declare(strict_types=1);
namespace App\Actions\Contacts;

use App\Models\ContactAssignment;
use App\Models\ContactPerson;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SaveContact
{
    public function execute(User $actor, ?ContactPerson $contact, array $data): ContactPerson
    {
        return DB::transaction(function () use ($actor,$contact,$data): ContactPerson {
            $actor=User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->hasPermission('contacts.manage'),403);
            if ($contact) {
                $contact=ContactPerson::query()->lockForUpdate()->findOrFail($contact->id);
                if (!$actor->scope_all) {
                    $assignments=ContactAssignment::query()->where('contact_person_id',$contact->id);
                    $visible=DB::table('user_chapter_assignments')->select('chapter_id')->where('user_id',$actor->id);
                    abort_unless((clone $assignments)->exists()
                        && !(clone $assignments)->whereNotIn('chapter_id',$visible)->exists(),404);
                }
            } else abort_unless($actor->scope_all,403);
            $contact ??= new ContactPerson();
            $contact->fill($data)->save();
            return $contact;
        },3);
    }
}
