<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StorageFile;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\Finance\FinancialSnapshotBuilder;
use App\Services\Security\ScopeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

final class DraftBudgetPdfService
{
    public function __construct(
        private readonly BrandingAssetService $brandingAssets,
        private readonly FileStorageService $files,
        private readonly FinancialSnapshotBuilder $builder,
    ) {}

    public function generate(TripPhase $tripPhase, ?User $user = null): StorageFile
    {
        if ($user !== null) {
            app(ScopeService::class)
                ->authorize($user, 'budgets.manage', (int) $tripPhase->id);
        }
        $cases = $tripPhase->billingCases()->orderBy('invoice_type')->get();
        abort_unless($cases->isNotEmpty(), 422, 'El viaje no tiene matriz financiera.');
        $sheets = [];
        foreach ($cases as $case) {
            foreach (['initial', 'final'] as $stage) {
                if ($stage === 'initial' && $case->billing_mode === 'final_only') {
                    continue;
                }
                $pointer = $stage === 'initial' ? 'currentInitial' : 'currentFinal';
                $invoice = $case->{$pointer};
                $snapshot = $invoice?->snapshot;
                if (! is_array($snapshot) && ($case->lines()->count() === 0
                    || $case->lines()->whereDoesntHave('versions', fn ($q) => $q->where('stage', $stage)->where('is_captured', true))->exists())) {
                    continue;
                }
                if (! is_array($snapshot)) {
                    $snapshot = $this->builder->build($case, $stage, null, true)['snapshot'];
                }
                $days = [];
                $start = CarbonImmutable::parse($snapshot['metadata']['starts_on']);
                $end = CarbonImmutable::parse($snapshot['metadata']['ends_on']);
                for ($day = $start; $day->lte($end); $day = $day->addDay()) {
                    $days[] = $day->toDateString();
                }
                foreach (array_chunk($days, 14) as $block) {
                    $sheets[] = [
                        'type' => $case->invoice_type, 'stage' => $stage, 'snapshot' => $snapshot, 'days' => $block,
                    ];
                }
            }
        }
        abort_unless($sheets !== [], 422, 'La matriz aún no tiene una etapa completa.');
        $pdf = Pdf::loadView('pdf.draft-budget', [
            'tripPhase' => $tripPhase, 'sheets' => $sheets,
            'brand' => $this->brandingAssets->colors(),
            'logoDataUri' => $this->brandingAssets->logoDataUri(),
        ])->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false,
            'chroot' => public_path('build')])->setPaper('a4', 'landscape');
        $contents = $pdf->output();
        $digest = hash('sha256', json_encode([$sheets, $this->brandingAssets->documentStyleKey()], JSON_THROW_ON_ERROR));
        $fileName = 'draft-budget-'.$tripPhase->id.'-'.substr($digest, 0, 16).'.pdf';
        $key = 'draft-budgets/'.$tripPhase->id.'/'.$fileName;
        $disk = $this->files->activeDisk();
        if (! Storage::disk($disk)->exists($key)) {
            Storage::disk($disk)->put($key, $contents);
        }
        $file = StorageFile::query()->firstOrCreate(['object_key' => $key], [
            'provider' => $this->files->providerForDisk($disk),
            'bucket' => $this->files->bucketForDisk($disk),
            'original_name' => $fileName, 'mime_type' => 'application/pdf',
            'size_bytes' => strlen($contents), 'checksum' => hash('sha256', $contents),
            'public_url' => null,
            'uploaded_by_id' => $user?->id,
        ]);
        $tripPhase->forceFill(['draft_pdf_file_id' => $file->id])->save();

        return $file;
    }
}
