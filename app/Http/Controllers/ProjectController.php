<?php

namespace App\Http\Controllers;

use App\Actions\Projects\SaveProject;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Community;
use App\Models\Country;
use App\Models\Project;
use App\Services\Security\AuditWriter;
use App\Services\Security\ScopeService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(ScopeService $scope): Response
    {
        $actor = request()->user();

        return Inertia::render('Projects/Index', [
            'projects' => Inertia::defer(fn () => Project::query()
                ->where(fn ($q) => $actor->scope_all ? $q : $q
                    ->whereIn('projects.id', DB::table('user_project_assignments')->select('project_id')->where('user_id', $actor->id))
                    ->orWhereIn('projects.id', $scope->tripPhases($actor)->select('trip_phases.project_id')))
                ->select(['id', 'country_id', 'community_id', 'code', 'name', 'started_on', 'closed_on', 'description'])
                ->with(['country:id,name', 'community:id,name,country_id'])
                ->withCount('tripPhases')
                ->orderBy('code')
                ->orderBy('id')->cursorPaginate(25), 'projects'),
            'countries' => Inertia::defer(fn () => Country::query()
                ->orderBy('name')
                ->limit(25)->get(['id', 'name']), 'projects'),
            'communities' => Inertia::defer(fn () => Community::query()
                ->with('country:id,name')
                ->orderBy('name')
                ->limit(25)->get(['id', 'country_id', 'name']), 'projects'),
        ]);
    }

    public function store(StoreProjectRequest $request, SaveProject $action): RedirectResponse
    {
        $action->execute($request->user(), null, $request->validated());

        return back()->with('success', 'Proyecto creado correctamente.');
    }

    public function update(UpdateProjectRequest $request, Project $project,
        SaveProject $action): RedirectResponse
    {
        $action->execute($request->user(), $project, $request->validated());

        return back()->with('success', 'Proyecto actualizado correctamente.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        $this->authorizeProjectGrant(request(), $project);
        try {
            DB::transaction(function () use ($project): void {
                $before = $project->only(['code', 'name']);
                $project->delete();
                app(AuditWriter::class)->record(request()->user(), 'project_deleted', $project, null, $before, [],
                    (string) request()->attributes->get('correlation_id', Str::uuid()));
            });
        } catch (QueryException) {
            return back()->withErrors([
                'project' => 'No se puede eliminar porque tiene fases de viaje u otros registros relacionados.',
            ]);
        }

        return back()->with('success', 'Proyecto eliminado correctamente.');
    }

    private function authorizeProjectGrant(Request $request, Project $project): void
    {
        abort_unless($request->user()->scope_all || DB::table('user_project_assignments')
            ->where('user_id', $request->user()->id)->where('project_id', $project->id)->exists(), 404);
    }
}
