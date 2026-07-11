<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        Team::query()->create($this->validatedData($request));

        return back()->with('success', 'Equipo creado correctamente.');
    }

    public function update(Request $request, Team $team): RedirectResponse
    {
        $team->fill($this->validatedData($request, $team->id))->save();

        return back()->with('success', 'Equipo actualizado correctamente.');
    }

    public function destroy(Team $team): RedirectResponse
    {
        try {
            $team->delete();
        } catch (QueryException) {
            return back()->withErrors([
                'team' => 'No se puede eliminar porque tiene proyectos, viajes o movimientos relacionados.',
            ]);
        }

        return back()->with('success', 'Equipo eliminado correctamente.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'chapter_id' => ['required', 'integer', Rule::exists('chapters', 'id')],
            'name' => [
                'required',
                'string',
                'max:160',
                Rule::unique('teams', 'name')
                    ->where('chapter_id', $request->integer('chapter_id'))
                    ->ignore($id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'credit_balance' => ['required', 'numeric', 'min:-999999999.99', 'max:999999999.99'],
        ]);
    }
}
