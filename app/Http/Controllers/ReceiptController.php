<?php

namespace App\Http\Controllers;

use App\Models\ActualExpense;
use App\Models\Receipt;
use App\Models\StorageFile;
use App\Services\UploadedFileStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReceiptController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Receipts/Index', [
            'actualExpenses' => Inertia::defer(fn () => ActualExpense::query()
                ->with(['tripPhase.project:id,code,name', 'expenseCategory:id,name,fund_type'])
                ->orderByDesc('reported_at')
                ->get(['id', 'trip_phase_id', 'expense_category_id', 'description', 'real_total', 'receipt_number', 'fund_type']), 'receipts'),
            'receipts' => Inertia::defer(fn () => Receipt::query()
                ->with([
                    'actualExpense:id,trip_phase_id,description,real_total',
                    'actualExpense.tripPhase.project:id,code,name',
                    'storageFile:id,object_key,original_name,mime_type,size_bytes,provider',
                ])
                ->orderByDesc('created_at')
                ->get(['id', 'actual_expense_id', 'storage_file_id', 'receipt_number', 'issued_on', 'amount', 'created_at']), 'receipts'),
        ]);
    }

    public function store(Request $request, UploadedFileStorageService $storage): RedirectResponse
    {
        $data = $request->validate([
            'actual_expense_id' => ['required', 'integer', Rule::exists('actual_expenses', 'id')],
            'receipt_number' => ['nullable', 'string', 'max:120'],
            'issued_on' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);

        $file = $storage->store(
            $request->file('file'),
            'receipts/'.$data['actual_expense_id'],
            $request->user()
        );

        Receipt::query()->create([
            'actual_expense_id' => $data['actual_expense_id'],
            'storage_file_id' => $file->id,
            'receipt_number' => $data['receipt_number'] ?? null,
            'issued_on' => $data['issued_on'] ?? null,
            'amount' => $data['amount'],
        ]);

        return back()->with('success', 'Comprobante subido correctamente.');
    }

    public function update(Request $request, Receipt $receipt): RedirectResponse
    {
        $data = $request->validate([
            'receipt_number' => ['nullable', 'string', 'max:120'],
            'issued_on' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
        ]);

        $receipt->fill($data)->save();

        return back()->with('success', 'Comprobante actualizado correctamente.');
    }

    public function destroy(Receipt $receipt): RedirectResponse
    {
        $receipt->load('storageFile');
        $storageFile = $receipt->storageFile;
        $disk = $storageFile?->provider === 'cloudflare_r2' ? 'r2' : 'local';

        $receipt->delete();

        if ($storageFile instanceof StorageFile) {
            Storage::disk($disk)->delete($storageFile->object_key);
            $storageFile->delete();
        }

        return back()->with('success', 'Comprobante eliminado correctamente.');
    }

    public function show(Receipt $receipt): StreamedResponse
    {
        $receipt->load('storageFile');
        abort_unless($receipt->storageFile instanceof StorageFile, 404);

        $disk = $receipt->storageFile->provider === 'cloudflare_r2' ? 'r2' : 'local';

        return Storage::disk($disk)->download(
            $receipt->storageFile->object_key,
            $receipt->storageFile->original_name,
            ['Content-Type' => $receipt->storageFile->mime_type]
        );
    }
}
