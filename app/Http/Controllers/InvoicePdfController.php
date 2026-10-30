<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\StorageFile;
use App\Services\InvoicePdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoicePdfController extends Controller
{
    public function store(Invoice $invoice, InvoicePdfService $service): RedirectResponse
    {
        request()->user()->can('update', $invoice) || abort(404);
        $service->generate($invoice, request()->user());

        return back()->with('success', 'PDF de invoice generado correctamente.');
    }

    public function show(Invoice $invoice): StreamedResponse
    {
        request()->user()->can('view', $invoice) || abort(404);
        $invoice->load('pdfFile');
        abort_unless($invoice->pdfFile instanceof StorageFile, 404);

        return Storage::disk($invoice->pdfFile->diskName())->download(
            $invoice->pdfFile->object_key,
            $invoice->pdfFile->original_name,
            ['Content-Type' => 'application/pdf']
        );
    }
}
