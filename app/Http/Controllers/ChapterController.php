<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\ChapterType;
use App\Models\Team;
use App\Models\University;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ChapterController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Chapters/Index', [
            'chapters' => Inertia::defer(fn () => Chapter::query()
                ->select(['id', 'chapter_type_id', 'university_id', 'name', 'description'])
                ->with(['chapterType:id,name', 'university:id,name'])
                ->withCount('teams')
                ->orderBy('name')
                ->get(), 'chapters'),
            'teams' => Inertia::defer(fn () => Team::query()
                ->select(['id', 'chapter_id', 'name', 'description', 'credit_balance'])
                ->with('chapter:id,name')
                ->orderBy('name')
                ->get(), 'chapters'),
            'chapterTypes' => Inertia::defer(fn () => ChapterType::query()
                ->orderBy('name')
                ->get(['id', 'name']), 'chapters'),
            'universities' => Inertia::defer(fn () => University::query()
                ->orderBy('name')
                ->get(['id', 'name']), 'chapters'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Chapter::query()->create($this->validatedData($request));

        return back()->with('success', 'Capitulo creado correctamente.');
    }

    public function update(Request $request, Chapter $chapter): RedirectResponse
    {
        $chapter->fill($this->validatedData($request, $chapter->id))->save();

        return back()->with('success', 'Capitulo actualizado correctamente.');
    }

    public function destroy(Chapter $chapter): RedirectResponse
    {
        try {
            $chapter->delete();
        } catch (QueryException) {
            return back()->withErrors([
                'chapter' => 'No se puede eliminar porque tiene equipos u otros registros relacionados.',
            ]);
        }

        return back()->with('success', 'Capitulo eliminado correctamente.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'chapter_type_id' => ['required', 'integer', Rule::exists('chapter_types', 'id')],
            'university_id' => ['nullable', 'integer', Rule::exists('universities', 'id')],
            'name' => ['required', 'string', 'max:160', Rule::unique('chapters', 'name')->ignore($id)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
