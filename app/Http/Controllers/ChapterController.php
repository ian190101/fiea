<?php

namespace App\Http\Controllers;

use App\Actions\Chapters\SaveChapter;
use App\Http\Requests\SaveChapterRequest;
use App\Models\Chapter;
use App\Models\ChapterType;
use App\Models\CreditAccount;
use App\Models\Team;
use App\Models\University;
use App\Support\Decimal;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ChapterController extends Controller
{
    public function index(): Response
    {
        $actor = request()->user();
        $chapters = DB::table('user_chapter_assignments')->select('chapter_id')->where('user_id', $actor->id);

        return Inertia::render('Chapters/Index', [
            'chapters' => Inertia::defer(fn () => Chapter::query()
                ->when(! $actor->scope_all, fn ($q) => $q->whereIn('chapters.id', $chapters))
                ->select(['id', 'chapter_type_id', 'university_id', 'name', 'description'])
                ->with(['chapterType:id,name', 'university:id,name'])
                ->withCount('teams')
                ->orderBy('name')
                ->orderBy('id')->cursorPaginate(25, ['*'], 'chapters_cursor')->withQueryString(), 'chapters'),
            'teams' => Inertia::defer(fn () => Team::query()
                ->when(! $actor->scope_all, fn ($q) => $q->whereIn('teams.chapter_id', $chapters))
                ->select(['id', 'chapter_id', 'name', 'description'])
                ->with(['chapter:id,name', 'creditAccount:id,team_id,balance'])
                ->orderBy('name')
                ->orderBy('id')->cursorPaginate(25, ['*'], 'teams_cursor')->withQueryString()
                ->through(fn (Team $team) => array_replace($team->toArray(), ['credit_balance' => $team->creditAccount?->balance])), 'chapters'),
            'creditTotals' => Inertia::defer(function () use ($actor, $chapters): array {
                $totals = [];
                CreditAccount::query()->where('currency', 'USD')->whereHas('team', fn ($q) => $q
                    ->when(! $actor->scope_all, fn ($q) => $q->whereIn('chapter_id', $chapters)))
                    ->with('team:id,chapter_id')->chunkById(500, function ($accounts) use (&$totals): void {
                        foreach ($accounts as $account) {
                            $key = (string) $account->team->chapter_id;
                            $totals[$key] = Decimal::money(Decimal::add($totals[$key] ?? '0', $account->balance));
                        }
                    });

                return $totals;
            }, 'chapters'),
            'chapterTypes' => Inertia::defer(fn () => ChapterType::query()
                ->orderBy('name')
                ->limit(25)->get(['id', 'name']), 'chapters'),
            'universities' => Inertia::defer(fn () => University::query()
                ->orderBy('name')
                ->limit(25)->get(['id', 'name']), 'chapters'),
        ]);
    }

    public function store(SaveChapterRequest $request, SaveChapter $action): RedirectResponse
    {
        $action->execute($request->user(), null, $request->validated());

        return back()->with('success', 'Capitulo creado correctamente.');
    }

    public function update(SaveChapterRequest $request, Chapter $chapter,
        SaveChapter $action): RedirectResponse
    {
        $action->execute($request->user(), $chapter, $request->validated());

        return back()->with('success', 'Capitulo actualizado correctamente.');
    }

    public function destroy(Chapter $chapter): RedirectResponse
    {
        $this->authorizeChapter(request(), $chapter->id);
        try {
            $chapter->delete();
        } catch (QueryException) {
            return back()->withErrors([
                'chapter' => 'No se puede eliminar porque tiene equipos u otros registros relacionados.',
            ]);
        }

        return back()->with('success', 'Capitulo eliminado correctamente.');
    }

    private function authorizeChapter(Request $request, int $chapterId): void
    {
        abort_unless($request->user()->scope_all || DB::table('user_chapter_assignments')
            ->where('user_id', $request->user()->id)->where('chapter_id', $chapterId)->exists(), 404);
    }
}
