<?php
declare(strict_types=1);

namespace App\Services\Security;

use App\Models\TripPhase;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ScopeService
{
    public function tripPhases(User $user): Builder
    {
        $query = TripPhase::query();
        if (!$user->is_active) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->scope_all) {
            return $query;
        }
        // La cláusula agrupada impide que un OR amplíe otros filtros del llamador.
        return $query->where(function (Builder $q) use ($user): void {
            $q->where('assigned_technician_id', $user->id)
                ->orWhereIn('project_id', DB::table('user_project_assignments')
                    ->select('project_id')->where('user_id', $user->id))
                ->orWhereIn('team_id', DB::table('teams')->select('id')
                    ->whereIn('chapter_id', DB::table('user_chapter_assignments')
                        ->select('chapter_id')->where('user_id', $user->id)));
        });
    }

    public function permits(User $user, int $tripPhaseId): bool
    {
        return $this->tripPhases($user)->whereKey($tripPhaseId)->exists();
    }

    public function constrain(Builder $query, User $user, string $column): Builder
    {
        return $query->whereIn($column, $this->tripPhases($user)->select('trip_phases.id'));
    }

    public function authorize(User $user, string $permission, int $tripPhaseId): void
    {
        abort_unless($user->hasPermission($permission), 403,
            'No tienes permiso para esta operación.');
        abort_unless($this->permits($user, $tripPhaseId), 404);
    }
}
