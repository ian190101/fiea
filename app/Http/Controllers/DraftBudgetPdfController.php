<?php

namespace App\Http\Controllers;

use App\Models\StorageFile;
use App\Models\TripPhase;
use App\Services\DraftBudgetPdfService;
use App\Services\Security\ScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DraftBudgetPdfController extends Controller
{
    public function store(TripPhase $tripPhase, DraftBudgetPdfService $service): RedirectResponse
    {
        app(ScopeService::class)->authorize(request()->user(), 'budgets.manage', (int) $tripPhase->id);
        $service->generate($tripPhase, request()->user());

        return back()->with('success', 'Draft Budget PDF generado correctamente.');
    }

    public function show(TripPhase $tripPhase): StreamedResponse
    {
        abort_unless(request()->user()->hasAnyPermission(['budgets.view', 'budgets.manage']), 403);
        abort_unless(app(ScopeService::class)->permits(request()->user(), (int) $tripPhase->id), 404);
        $tripPhase->load('draftPdfFile');
        abort_unless($tripPhase->draftPdfFile instanceof StorageFile, 404);

        $disk = $tripPhase->draftPdfFile->provider === 'cloudflare_r2' ? 'r2' : 'local';

        return Storage::disk($disk)->download(
            $tripPhase->draftPdfFile->object_key,
            $tripPhase->draftPdfFile->original_name,
            ['Content-Type' => 'application/pdf']
        );
    }
}
