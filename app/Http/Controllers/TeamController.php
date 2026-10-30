<?php

namespace App\Http\Controllers;

use App\Actions\Chapters\SaveTeam;
use App\Http\Requests\SaveTeamRequest;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamController extends Controller
{
    public function store(SaveTeamRequest $request, SaveTeam $action): RedirectResponse
    {
        $action->execute($request->user(), null, $request->validated());

        return back()->with('success', 'Equipo creado correctamente.');
    }

    public function update(SaveTeamRequest $request, Team $team, SaveTeam $action): RedirectResponse
    {
        $action->execute($request->user(), $team, $request->validated());

        return back()->with('success', 'Equipo actualizado correctamente.');
    }

    public function destroy(Team $team): RedirectResponse
    {
        $this->authorizeChapter(request(), (int) $team->chapter_id);
        if ($team->contactAssignments()->exists()) {
            return back()->withErrors(['team' => 'El equipo tiene asignaciones de contacto; desvincúlalas antes de eliminarlo.']);
        }
        try {
            $team->delete();
        } catch (QueryException) {
            return back()->withErrors([
                'team' => 'No se puede eliminar porque tiene proyectos, viajes o movimientos relacionados.',
            ]);
        }

        return back()->with('success', 'Equipo eliminado correctamente.');
    }

    private function authorizeChapter(Request $request, int $chapterId): void
    {
        abort_unless($request->user()->scope_all || DB::table('user_chapter_assignments')
            ->where('user_id', $request->user()->id)->where('chapter_id', $chapterId)->exists(), 404);
    }
}
