<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCatalogRequest;
use App\Models\ChapterType;
use App\Models\Community;
use App\Models\Country;
use App\Models\ExpenseCategory;
use App\Models\University;
use App\Services\CatalogService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Catalogs/Index', [
            'canManage' => request()->user()->scope_all && request()->user()->hasPermission('catalogs.manage'),
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

    public function store(SaveCatalogRequest $request, string $catalog,
        CatalogService $service): RedirectResponse
    {
        $service->save($request->user(), $catalog, null, $request->validated());

        return back()->with('success', 'Catalogo creado correctamente.');
    }

    public function update(SaveCatalogRequest $request, string $catalog, int $id,
        CatalogService $service): RedirectResponse
    {
        $service->save($request->user(), $catalog, $id, $request->validated());

        return back()->with('success', 'Catalogo actualizado correctamente.');
    }

    public function destroy(string $catalog, int $id, CatalogService $service): RedirectResponse
    {
        try {
            $service->destroy(request()->user(), $catalog, $id);
        } catch (QueryException) {
            return back()->withErrors([
                'catalog' => 'No se puede eliminar porque ya esta relacionado con otros registros.',
            ]);
        }

        return back()->with('success', 'Catalogo eliminado correctamente.');
    }
}
