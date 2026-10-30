<?php
declare(strict_types=1);
namespace App\Actions\Chapters;

use App\Models\Chapter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SaveChapter
{
    public function execute(User $actor, ?Chapter $chapter, array $data): Chapter
    {
        return DB::transaction(function () use ($actor,$chapter,$data): Chapter {
            $actor=User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->hasPermission('chapters.manage'),403);
            if ($chapter) {
                $chapter=Chapter::query()->lockForUpdate()->findOrFail($chapter->id);
                abort_unless($actor->scope_all || DB::table('user_chapter_assignments')
                    ->where('user_id',$actor->id)->where('chapter_id',$chapter->id)->exists(),404);
            } else abort_unless($actor->scope_all,403);
            $chapter ??= new Chapter();
            $chapter->fill($data)->save();
            return $chapter;
        },3);
    }
}
