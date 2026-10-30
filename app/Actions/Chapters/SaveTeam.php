<?php

declare(strict_types=1);

namespace App\Actions\Chapters;

use App\Models\ContactAssignment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SaveTeam
{
    public function execute(User $actor, ?Team $team, array $data): Team
    {
        return DB::transaction(function () use ($actor, $team, $data): Team {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->hasPermission('chapters.manage'), 403);
            if ($team) {
                $team = Team::query()->lockForUpdate()->findOrFail($team->id);
                $this->authorizeChapter($actor, (int) $team->chapter_id);
            }
            $this->authorizeChapter($actor, (int) $data['chapter_id']);
            $team ??= new Team;
            $previousChapter = $team->chapter_id;
            $team->fill($data)->save();
            if ($previousChapter !== null && (int) $previousChapter !== (int) $team->chapter_id) {
                // La adscripción del contacto de equipo sigue al equipo dentro de la misma transacción.
                ContactAssignment::query()->where('team_id', $team->id)->update(['chapter_id' => $team->chapter_id]);
            }

            return $team;
        }, 3);
    }

    private function authorizeChapter(User $actor, int $id): void
    {
        abort_unless($actor->scope_all || DB::table('user_chapter_assignments')
            ->where('user_id', $actor->id)->where('chapter_id', $id)->exists(), 404);
    }
}
