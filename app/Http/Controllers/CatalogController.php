<?php

namespace App\Http\Controllers;

use App\Models\ChapterType;
use App\Models\Community;
use App\Models\Country;
use App\Models\ExpenseCategory;
use App\Models\University;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    private const CATALOGS = [
        'chapter-types' => ChapterType::class,
        'countries' => Country::class,
        'communities' => Community::class,
        'universities' => University::class,
        'expense-categories' => ExpenseCategory::class,
    ];

    public function index(): Response
    {
        return Inertia::render('Catalogs/Index', [
            'catalogs' => Inertia::defer(fn () => [
                'chapterTypes' => ChapterType::query()
                    ->orderBy('name')
                    ->get(['id', 'name', 'description']),
                'countries' => Country::query()
                    ->orderBy('name')
                    ->get(['id', 'name', 'description']),
                'communities' => Community::query()
                    ->with('country:id,name')
                    ->orderBy('name')
                    ->get(['id', 'country_id', 'name', 'description']),
                'universities' => University::query()
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'expenseCategories' => ExpenseCategory::query()
                    ->orderBy('name')
                    ->get([
                        'id',
                        'name',
                        'description',
                        'fund_type',
                        'applies_service_fee',
                        'applies_contingency',
                        'service_fee_percentage',
                    ]),
            ], 'catalogs'),
        ]);
    }

    public function store(Request $request, string $catalog): RedirectResponse
    {
        $modelClass = $this->resolveModel($catalog);

        $modelClass::query()->create($this->validatedData($request, $catalog));

        return back()->with('success', 'Catalogo creado correctamente.');
    }

    public function update(Request $request, string $catalog, int $id): RedirectResponse
    {
        $model = $this->findModel($catalog, $id);

        $model->fill($this->validatedData($request, $catalog, $model->id))->save();

        return back()->with('success', 'Catalogo actualizado correctamente.');
    }

    public function destroy(string $catalog, int $id): RedirectResponse
    {
        $model = $this->findModel($catalog, $id);

        try {
            $model->delete();
        } catch (QueryException) {
            return back()->withErrors([
                'catalog' => 'No se puede eliminar porque ya esta relacionado con otros registros.',
            ]);
        }

        return back()->with('success', 'Catalogo eliminado correctamente.');
    }

    /**
     * @return class-string<Model>
     */
    private function resolveModel(string $catalog): string
    {
        abort_unless(array_key_exists($catalog, self::CATALOGS), 404);

        return self::CATALOGS[$catalog];
    }

    private function findModel(string $catalog, int $id): Model
    {
        $modelClass = $this->resolveModel($catalog);

        return $modelClass::query()->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, string $catalog, ?int $id = null): array
    {
        return match ($catalog) {
            'chapter-types' => $request->validate([
                'name' => ['required', 'string', 'max:120', Rule::unique('chapter_types', 'name')->ignore($id)],
                'description' => ['nullable', 'string', 'max:255'],
            ]),
            'countries' => $request->validate([
                'name' => ['required', 'string', 'max:120', Rule::unique('countries', 'name')->ignore($id)],
                'description' => ['nullable', 'string', 'max:255'],
            ]),
            'communities' => $request->validate([
                'country_id' => ['required', 'integer', Rule::exists('countries', 'id')],
                'name' => [
                    'required',
                    'string',
                    'max:120',
                    Rule::unique('communities', 'name')
                        ->where('country_id', $request->integer('country_id'))
                        ->ignore($id),
                ],
                'description' => ['nullable', 'string', 'max:255'],
            ]),
            'universities' => $request->validate([
                'name' => ['required', 'string', 'max:160', Rule::unique('universities', 'name')->ignore($id)],
            ]),
            'expense-categories' => $request->validate([
                'name' => ['required', 'string', 'max:120', Rule::unique('expense_categories', 'name')->ignore($id)],
                'description' => ['nullable', 'string', 'max:255'],
                'fund_type' => ['required', Rule::in(['DR', 'WODR'])],
                'applies_service_fee' => ['required', 'boolean'],
                'applies_contingency' => ['required', 'boolean'],
                'service_fee_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            ]),
        };
    }
}
