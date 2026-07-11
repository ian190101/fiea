<?php

namespace App\Http\Controllers;

use App\Models\Community;
use App\Models\Country;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Projects/Index', [
            'projects' => Inertia::defer(fn () => Project::query()
                ->select(['id', 'country_id', 'community_id', 'code', 'name', 'started_on', 'closed_on', 'description'])
                ->with(['country:id,name', 'community:id,name,country_id'])
                ->withCount('tripPhases')
                ->orderBy('code')
                ->get(), 'projects'),
            'countries' => Inertia::defer(fn () => Country::query()
                ->orderBy('name')
                ->get(['id', 'name']), 'projects'),
            'communities' => Inertia::defer(fn () => Community::query()
                ->with('country:id,name')
                ->orderBy('name')
                ->get(['id', 'country_id', 'name']), 'projects'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Project::query()->create($this->validatedData($request));

        return back()->with('success', 'Proyecto creado correctamente.');
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $project->fill($this->validatedData($request, $project->id))->save();

        return back()->with('success', 'Proyecto actualizado correctamente.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        try {
            $project->delete();
        } catch (QueryException) {
            return back()->withErrors([
                'project' => 'No se puede eliminar porque tiene fases de viaje u otros registros relacionados.',
            ]);
        }

        return back()->with('success', 'Proyecto eliminado correctamente.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, ?int $id = null): array
    {
        $validator = Validator::make($request->all(), [
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')],
            'community_id' => ['nullable', 'integer', Rule::exists('communities', 'id')],
            'code' => ['required', 'string', 'max:80', Rule::unique('projects', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:160'],
            'started_on' => ['nullable', 'date'],
            'closed_on' => ['nullable', 'date', 'after_or_equal:started_on'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $validator->after(function ($validator) use ($request) {
            if (!$request->filled('community_id')) {
                return;
            }

            $community = Community::query()->find($request->integer('community_id'));
            if ($community && $community->country_id !== $request->integer('country_id')) {
                $validator->errors()->add('community_id', 'La comunidad seleccionada no pertenece al pais indicado.');
            }
        });

        return $validator->validate();
    }
}
