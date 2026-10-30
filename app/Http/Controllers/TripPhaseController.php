<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTripPhaseRequest;
use App\Http\Requests\UpdateTripPhaseRequest;
use App\Models\Project;
use App\Models\Team;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\Finance\TripPhaseMutationService;
use App\Services\Security\ScopeService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TripPhaseController extends Controller
{
    private const PHASE_OPTIONS = [
        'Initial Visit',
        'Implementation Trip',
        'Follow-up Trip',
        'Closeout',
    ];

    private const STATUS_OPTIONS = [
        'draft',
        'scheduled',
        'in_progress',
        'completed',
        'cancelled',
    ];

    public function index(ScopeService $scope): Response
    {
        $actor = request()->user();

        return Inertia::render('TripPhases/Index', [
            'tripPhases' => Inertia::defer(fn () => $scope->tripPhases($actor)
                ->select([
                    'id',
                    'project_id',
                    'team_id',
                    'assigned_technician_id',
                    'phase',
                    'starts_on',
                    'ends_on',
                    'volunteer_count',
                    'staff_count',
                    'status',
                    'version',
                ])
                ->with([
                    'project:id,code,name,country_id,community_id',
                    'project.country:id,name',
                    'project.community:id,name',
                    'team:id,chapter_id,name',
                    'team.chapter:id,name',
                    'assignedTechnician:id,name,username',
                ])
                ->orderByDesc('starts_on')
                ->orderByDesc('id')->cursorPaginate(25), 'trip-phases'),
            'projects' => Inertia::defer(fn () => Project::query()
                ->when(! $actor->scope_all, fn ($q) => $q->where(fn ($visible) => $visible
                    ->whereIn('projects.id', DB::table('user_project_assignments')->select('project_id')->where('user_id', $actor->id))
                    ->orWhereIn('projects.id', $scope->tripPhases($actor)->select('trip_phases.project_id'))))
                ->with(['country:id,name', 'community:id,name'])
                ->orderBy('code')
                ->limit(25)->get(['id', 'country_id', 'community_id', 'code', 'name']), 'trip-phases'),
            'teams' => Inertia::defer(fn () => Team::query()
                ->when(! $actor->scope_all, fn ($q) => $q->where(fn ($visible) => $visible
                    ->whereIn('teams.chapter_id', DB::table('user_chapter_assignments')->select('chapter_id')->where('user_id', $actor->id))
                    ->orWhereIn('teams.id', $scope->tripPhases($actor)->select('trip_phases.team_id'))))
                ->with('chapter:id,name')
                ->orderBy('name')
                ->limit(25)->get(['id', 'chapter_id', 'name']), 'trip-phases'),
            'technicians' => Inertia::defer(fn () => User::query()
                ->where('is_active', true)
                ->when(! ($actor->scope_all && $actor->hasPermission('trip_phases.manage')), fn ($query) => $query
                    ->where(fn ($q) => $q->whereKey($actor->id)
                        ->orWhereIn('users.id', $scope->tripPhases($actor)->select('trip_phases.assigned_technician_id'))))
                ->whereHas('roles', fn ($query) => $query->whereIn('code', ['tecnico', 'superadmin']))
                ->orderBy('name')
                ->limit(25)->get(['id', 'name', 'username']), 'trip-phases'),
            'phaseOptions' => self::PHASE_OPTIONS,
            'statusOptions' => self::STATUS_OPTIONS,
        ]);
    }

    public function store(StoreTripPhaseRequest $request, TripPhaseMutationService $service): RedirectResponse
    {
        $service->create($request->user(), $request->validated());

        return back()->with('success', 'Fase de viaje creada correctamente.');
    }

    public function update(UpdateTripPhaseRequest $request, TripPhase $tripPhase,
        TripPhaseMutationService $service): RedirectResponse
    {
        $data = $request->validated();
        $service->update($request->user(), (int) $tripPhase->id, $data, (int) $data['version']);

        return back()->with('success', 'Fase de viaje actualizada correctamente.');
    }

    public function destroy(TripPhase $tripPhase): RedirectResponse
    {
        app(ScopeService::class)->authorize(request()->user(), 'trip_phases.manage', (int) $tripPhase->id);
        $version = request()->validate(['version' => ['required', 'integer', 'min:1']]);
        try {
            app(TripPhaseMutationService::class)->delete(request()->user(), (int) $tripPhase->id, (int) $version['version']);
        } catch (QueryException) {
            return back()->withErrors([
                'tripPhase' => 'No se puede eliminar porque tiene gastos, invoices o documentos relacionados.',
            ]);
        }

        return back()->with('success', 'Fase de viaje eliminada correctamente.');
    }
}
