<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Team;
use App\Models\TripPhase;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    public function index(): Response
    {
        return Inertia::render('TripPhases/Index', [
            'tripPhases' => Inertia::defer(fn () => TripPhase::query()
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
                ])
                ->with([
                    'project:id,code,name,country_id,community_id',
                    'project.country:id,name',
                    'project.community:id,name',
                    'team:id,chapter_id,name,credit_balance',
                    'team.chapter:id,name',
                    'assignedTechnician:id,name,username',
                ])
                ->orderByDesc('starts_on')
                ->get(), 'trip-phases'),
            'projects' => Inertia::defer(fn () => Project::query()
                ->with(['country:id,name', 'community:id,name'])
                ->orderBy('code')
                ->get(['id', 'country_id', 'community_id', 'code', 'name']), 'trip-phases'),
            'teams' => Inertia::defer(fn () => Team::query()
                ->with('chapter:id,name')
                ->orderBy('name')
                ->get(['id', 'chapter_id', 'name', 'credit_balance']), 'trip-phases'),
            'technicians' => Inertia::defer(fn () => User::query()
                ->where('is_active', true)
                ->whereHas('roles', fn ($query) => $query->whereIn('code', ['tecnico', 'superadmin']))
                ->orderBy('name')
                ->get(['id', 'name', 'username']), 'trip-phases'),
            'phaseOptions' => self::PHASE_OPTIONS,
            'statusOptions' => self::STATUS_OPTIONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        TripPhase::query()->create($this->validatedData($request));

        return back()->with('success', 'Fase de viaje creada correctamente.');
    }

    public function update(Request $request, TripPhase $tripPhase): RedirectResponse
    {
        $tripPhase->fill($this->validatedData($request))->save();

        return back()->with('success', 'Fase de viaje actualizada correctamente.');
    }

    public function destroy(TripPhase $tripPhase): RedirectResponse
    {
        try {
            $tripPhase->delete();
        } catch (QueryException) {
            return back()->withErrors([
                'tripPhase' => 'No se puede eliminar porque tiene gastos, invoices o documentos relacionados.',
            ]);
        }

        return back()->with('success', 'Fase de viaje eliminada correctamente.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request): array
    {
        return $request->validate([
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')],
            'team_id' => ['required', 'integer', Rule::exists('teams', 'id')],
            'assigned_technician_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'phase' => ['required', 'string', 'max:40', Rule::in(self::PHASE_OPTIONS)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'volunteer_count' => ['required', 'integer', 'min:0', 'max:65535'],
            'staff_count' => ['required', 'integer', 'min:0', 'max:65535'],
            'status' => ['required', 'string', Rule::in(self::STATUS_OPTIONS)],
        ]);
    }
}
