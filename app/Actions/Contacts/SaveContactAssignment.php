<?php
declare(strict_types=1);
namespace App\Actions\Contacts;

use App\Models\ContactAssignment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SaveContactAssignment
{
    public function execute(User $actor, ?ContactAssignment $assignment, array $data): ContactAssignment
    {
        return DB::transaction(function () use ($actor,$assignment,$data): ContactAssignment {
            $actor=User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->hasPermission('contacts.manage'),403);
            if ($assignment) {
                $assignment=ContactAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
                $this->authorizeChapter($actor,(int)$assignment->chapter_id);
            }
            if (!empty($data['team_id'])) {
                $team=Team::query()->findOrFail($data['team_id']);
                if (!empty($data['chapter_id']))
                    abort_unless((int)$team->chapter_id===(int)$data['chapter_id'],422);
                $data['chapter_id']=$team->chapter_id;
            }
            $this->authorizeChapter($actor,(int)$data['chapter_id']);
            if (!$actor->scope_all) {
                $other=ContactAssignment::query()->where('contact_person_id',$data['contact_person_id']);
                $visible=DB::table('user_chapter_assignments')->select('chapter_id')->where('user_id',$actor->id);
                abort_unless((clone $other)->exists() && !(clone $other)
                    ->whereNotIn('chapter_id',$visible)->exists(),404);
            }
            $assignment ??= new ContactAssignment();
            $assignment->fill($data)->save();
            return $assignment;
        },3);
    }
    private function authorizeChapter(User $actor,int $id): void
    {
        abort_unless($actor->scope_all || DB::table('user_chapter_assignments')
            ->where('user_id',$actor->id)->where('chapter_id',$id)->exists(),404);
    }
}
