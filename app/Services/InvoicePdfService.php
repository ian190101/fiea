<?php

namespace App\Services;

use App\Models\ActualExpense;
use App\Models\Invoice;
use App\Models\StorageFile;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InvoicePdfService
{
    public function __construct(
        private readonly BrandingAssetService $brandingAssets,
        private readonly FileStorageService $files,
    ) {
    }

    public function generate(Invoice $invoice, ?User $user = null): StorageFile
    {
        $invoice->loadMissing([
            'tripPhase.project.country',
            'tripPhase.project.community',
            'tripPhase.team.chapter',
            'contactPerson',
            'createdBy',
            'approvedBy',
            'accountingReviewedBy',
            'tripPhase.actualExpenses.expenseCategory',
        ]);

        $expenses = $invoice->tripPhase?->actualExpenses
            ->sortBy([
                ['fund_type', 'asc'],
                ['description', 'asc'],
            ])
            ->values() ?? collect();

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'expenses' => $expenses,
            'categorySummary' => $this->categorySummary($expenses),
            'fundSummary' => $this->fundSummary($expenses),
            'generatedAt' => now(),
            'brand' => $this->brandingAssets->colors(),
            'logoDataUri' => $this->brandingAssets->logoDataUri(),
        ])->setPaper('letter', 'portrait');

        $contents = $pdf->output();
        $disk = $this->files->activeDisk();
        $fileName = $this->fileName($invoice);
        $objectKey = 'invoices/'.$invoice->id.'/'.$fileName;

        Storage::disk($disk)->put($objectKey, $contents);

        $storageFile = StorageFile::query()->updateOrCreate(
            ['object_key' => $objectKey],
            [
                'provider' => $this->files->providerForDisk($disk),
                'bucket' => $this->files->bucketForDisk($disk),
                'original_name' => $fileName,
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($contents),
                'checksum' => hash('sha256', $contents),
                'public_url' => $this->files->publicUrl($disk, $objectKey),
                'uploaded_by_id' => $user?->id,
            ]
        );

        $invoice->forceFill(['pdf_file_id' => $storageFile->id])->save();

        return $storageFile;
    }

    /**
     * @param \Illuminate\Support\Collection<int, ActualExpense> $expenses
     *
     * @return array<int, array{name: string, total: float}>
     */
    private function categorySummary($expenses): array
    {
        return $expenses
            ->groupBy(fn (ActualExpense $expense) => $expense->expenseCategory?->name ?? 'Uncategorized')
            ->map(fn ($items, string $name) => [
                'name' => $name,
                'total' => round((float) $items->sum(fn (ActualExpense $expense) => (float) $expense->real_total), 2),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * @param \Illuminate\Support\Collection<int, ActualExpense> $expenses
     *
     * @return array<int, array{name: string, total: float}>
     */
    private function fundSummary($expenses): array
    {
        return collect(['DR', 'WODR'])
            ->map(fn (string $fund) => [
                'name' => $fund,
                'total' => round((float) $expenses
                    ->where('fund_type', $fund)
                    ->sum(fn (ActualExpense $expense) => (float) $expense->real_total), 2),
            ])
            ->all();
    }

    private function fileName(Invoice $invoice): string
    {
        return Str::slug($invoice->code ?: 'invoice').'.pdf';
    }
}
