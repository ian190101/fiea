<?php

namespace App\Services;

use App\Models\EstimatedExpense;
use App\Models\StorageFile;
use App\Models\TripPhase;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DraftBudgetPdfService
{
    public function __construct(
        private readonly BrandingAssetService $brandingAssets,
        private readonly FileStorageService $files,
    ) {
    }

    public function generate(TripPhase $tripPhase, ?User $user = null): StorageFile
    {
        $tripPhase->loadMissing([
            'project.country',
            'project.community',
            'team.chapter',
            'assignedTechnician',
            'estimatedExpenses.expenseCategory',
        ]);

        $expenses = $tripPhase->estimatedExpenses
            ->sortBy([
                ['fund_type', 'asc'],
                ['description', 'asc'],
            ])
            ->values();

        $summary = $this->summary($expenses);
        $pdf = Pdf::loadView('pdf.draft-budget', [
            'tripPhase' => $tripPhase,
            'expenses' => $expenses,
            'summary' => $summary,
            'categorySummary' => $this->categorySummary($expenses),
            'fundSummary' => $this->fundSummary($expenses),
            'generatedAt' => now(),
            'brand' => $this->brandingAssets->colors(),
            'logoDataUri' => $this->brandingAssets->logoDataUri(),
        ])->setPaper('letter', 'portrait');

        $contents = $pdf->output();
        $disk = $this->files->activeDisk();
        $fileName = $this->fileName($tripPhase);
        $objectKey = 'draft-budgets/'.$tripPhase->id.'/'.$fileName;

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

        $tripPhase->forceFill(['draft_pdf_file_id' => $storageFile->id])->save();

        return $storageFile;
    }

    /**
     * @param \Illuminate\Support\Collection<int, EstimatedExpense> $expenses
     *
     * @return array<string, float>
     */
    private function summary($expenses): array
    {
        $dr = (float) $expenses
            ->where('fund_type', 'DR')
            ->sum(fn (EstimatedExpense $expense) => (float) $expense->estimated_total);
        $wodr = (float) $expenses
            ->where('fund_type', 'WODR')
            ->sum(fn (EstimatedExpense $expense) => (float) $expense->estimated_total);

        return [
            'dr' => round($dr, 2),
            'wodr' => round($wodr, 2),
            'grand_total' => round($dr + $wodr, 2),
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, EstimatedExpense> $expenses
     *
     * @return array<int, array{name: string, total: float}>
     */
    private function categorySummary($expenses): array
    {
        return $expenses
            ->groupBy(fn (EstimatedExpense $expense) => $expense->expenseCategory?->name ?? 'Uncategorized')
            ->map(fn ($items, string $name) => [
                'name' => $name,
                'total' => round((float) $items->sum(fn (EstimatedExpense $expense) => (float) $expense->estimated_total), 2),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * @param \Illuminate\Support\Collection<int, EstimatedExpense> $expenses
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
                    ->sum(fn (EstimatedExpense $expense) => (float) $expense->estimated_total), 2),
            ])
            ->all();
    }

    private function fileName(TripPhase $tripPhase): string
    {
        $projectCode = Str::slug($tripPhase->project?->code ?? 'project');
        $phase = Str::slug($tripPhase->phase);

        return "{$projectCode}-{$phase}-draft-budget.pdf";
    }
}
