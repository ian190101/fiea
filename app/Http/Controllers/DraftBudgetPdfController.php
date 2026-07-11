<?php

namespace App\Http\Controllers;

use App\Models\StorageFile;
use App\Models\TripPhase;
use App\Services\DraftBudgetPdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DraftBudgetPdfController extends Controller
{
    public function store(TripPhase $tripPhase, DraftBudgetPdfService $service): RedirectResponse
    {
        $service->generate($tripPhase, request()->user());

        return back()->with('success', 'Draft Budget PDF generado correctamente.');
    }

    public function show(TripPhase $tripPhase): StreamedResponse
    {
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
