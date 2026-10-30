<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\AccountingReview;
use App\Models\AuditLog;
use App\Models\BillingCase;
use App\Models\BillingCaseCode;
use App\Models\FinancialImport;
use App\Models\FinancialImportRow;
use App\Models\Invoice;
use App\Models\StorageFile;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\Security\ScopeService;
use App\Support\Decimal as D;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final class ControlImportService
{
    public function __construct(
        private readonly ControlWorkbookParser $parser,
        private readonly ScopeService $scope,
        private readonly ImportedPaymentRecorder $payments,
    ) {}

    public function preview(User $actor, UploadedFile $file, string $sheet): FinancialImport
    {
        $hash = hash_file('sha256', $file->getRealPath());
        abort_unless($hash !== false, 422);
        $rows = $this->parser->parse($file, $sheet);
        $mapping = 'control-v2:'.(config('finance.control_import_locale') ?? 'neutral');
        $existing = FinancialImport::query()->where('sha256', $hash)->where('sheet', $sheet)
            ->where('mapping_version', $mapping)->where('actor_id', $actor->id)->first();
        if ($existing) {
            if (! $existing->expires_at?->isFuture()) {
                $existing->forceFill(['expires_at' => now()->addDay()])->save();
            }
            if (! $existing->rows()->whereNull('applied_at')->exists()) {
                return $existing;
            }
        }
        $contents = file_get_contents($file->getRealPath());
        abort_unless($contents !== false, 422);
        $objectKey = 'financial-imports/'.$actor->id.'/'.$hash.'.xlsx';
        abort_unless(Storage::disk('local')->put($objectKey, $contents), 503, 'No se pudo conservar el archivo original.');
        $stored = StorageFile::query()->firstOrCreate(['object_key' => $objectKey], [
            'provider' => 'local', 'bucket' => null, 'original_name' => 'control-'.$sheet.'.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size_bytes' => strlen($contents), 'checksum' => $hash, 'public_url' => null,
            'uploaded_by_id' => $actor->id,
        ]);

        return DB::transaction(function () use ($actor, $hash, $sheet, $stored, $rows, $existing, $mapping): FinancialImport {
            $import = $existing ?? new FinancialImport;
            $import->fill(['sha256' => $hash, 'sheet' => $sheet, 'mapping_version' => $mapping,
                'actor_id' => $actor->id, 'status' => 'preview', 'source_file_id' => $stored->id,
                'expires_at' => now()->addDay(), 'applied_at' => null])->save();
            foreach ($import->rows()->whereNull('applied_at')->get() as $pending) {
                $pending->cases()->detach();
                $pending->delete();
            }
            $allCodes = collect($rows)->flatMap(fn ($row): array => $row->normalized['codes'] ?? [])->unique()->all();
            $aliases = BillingCaseCode::query()->whereIn('code', $allCodes)->get()->keyBy('code')->toBase();
            $allVisible = BillingCase::query()->whereIn('id', $aliases->pluck('billing_case_id')->unique())
                ->whereIn('trip_phase_id', $this->scope->tripPhases($actor)->select('trip_phases.id'))
                ->get(['id', 'trip_phase_id', 'version', 'invoice_type', 'billing_mode'])->keyBy('id');
            $applied = $import->rows()->whereNotNull('applied_at')->get()->keyBy(fn ($row): string => $row->section.':'.$row->source_row);
            foreach ($rows as $row) {
                if ($applied->has($row->section.':'.$row->sourceRow)) {
                    continue;
                }
                $codes = $row->normalized['codes'] ?? [];
                $resolved = $aliases->only($codes);
                $ids = $resolved->pluck('billing_case_id')->unique()->all();
                $visible = $allVisible->only($ids);
                $errors = $row->errors;
                if ($resolved->count() !== count(array_unique($codes)) || count($ids) !== $visible->count()) {
                    $errors[] = 'Identidad o jurisdicción del expediente sin resolver.';
                }
                if ($row->section === 'case' && $visible->count() !== 1) {
                    $errors[] = 'Identidad o jurisdicción del expediente sin resolver.';
                }
                if ($row->section === 'control_group' && ($visible->count() === 0 || count($ids) !== $visible->count())) {
                    $errors[] = 'Grupo de conciliación sin resolver.';
                }
                $target = $row->section === 'case' && $visible->count() === 1 ? $visible->first() : null;
                if ($target && trim($row->normalized['invoice_type'] ?? '') !== '' && $target->invoice_type !== $row->normalized['invoice_type']) {
                    $errors[] = 'El tipo del origen no coincide con el expediente.';
                }
                if ($target && ($row->normalized['billing_mode'] ?? 'standard') === 'final_only' && $target->billing_mode !== 'final_only') {
                    $errors[] = 'La modalidad solo Final no coincide con el expediente.';
                }
                if ($errors !== []) {
                    $target = null;
                }
                $saved = FinancialImportRow::query()->create([
                    'financial_import_id' => $import->id, 'section' => $row->section,
                    'source_row' => $row->sourceRow, 'raw' => $row->raw,
                    'normalized' => $row->normalized, 'errors' => array_values(array_unique($errors)),
                    'resolutions' => [], 'target_case_id' => $target?->id,
                    'expected_version' => $target?->version,
                ]);
                if ($row->section === 'control_group' && $errors === []) {
                    foreach ($visible as $case) {
                        $saved->cases()->attach($case->id, ['expected_version' => $case->version]);
                    }
                }
            }

            return $import;
        }, 3);
    }

    /** @param list<int> $rowIds */
    public function apply(User $actor, int $importId, array $rowIds): void
    {
        DB::transaction(function () use ($actor, $importId, $rowIds): void {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $import = FinancialImport::query()->with('sourceFile')
                ->where('actor_id', $actor->id)->findOrFail($importId);
            abort_unless($import->expires_at?->isFuture(), 409, 'La previsualización caducó.');
            $disk = $import->sourceFile->diskName();
            $contents = Storage::disk($disk)->get($import->sourceFile->object_key);
            abort_unless(hash_equals($import->sha256, hash('sha256', $contents)), 409, 'Cambió el archivo origen.');
            $rows = FinancialImportRow::query()->with('cases')
                ->where('financial_import_id', $import->id)
                ->whereIn('id', $rowIds)->orderBy('id')->get();
            abort_unless($rows->count() === count(array_unique($rowIds)), 409);
            $caseIds = $rows->pluck('target_case_id')->filter()->all();
            foreach ($rows as $row) {
                foreach ($row->cases as $case) {
                    $caseIds[] = $case->id;
                }
            }
            $cases = BillingCase::query()->whereIn('id', $caseIds)->orderBy('id')->get();
            TripPhase::query()->whereIn('id', $cases->pluck('trip_phase_id'))
                ->orderBy('id')->lockForUpdate()->get();
            $cases = BillingCase::query()->whereIn('id', $caseIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $originalVersions = $cases->mapWithKeys(fn ($case) => [$case->id => (int) $case->version])->all();
            $rows = FinancialImportRow::query()->with('cases')->whereIn('id', $rows->pluck('id'))
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($rows as $row) {
                if ($row->applied_at !== null) {
                    continue;
                }
                abort_unless(($row->errors ?? []) === [], 422, 'Hay filas sin resolver.');
                if ($row->section === 'control_group') {
                    $groupIds = $row->cases()->pluck('billing_cases.id')->all();
                    abort_unless($groupIds !== [], 422);
                    $members = $cases->only($groupIds);
                    abort_unless($members->count() === count($groupIds), 409);
                    foreach ($row->cases as $pivotCase) {
                        $locked = $cases->get($pivotCase->id);
                        abort_unless(($originalVersions[$locked->id] ?? null) === (int) $pivotCase->pivot->expected_version, 409);
                        $this->scope->authorize($actor, 'accounting.manage', (int) $locked->trip_phase_id);
                    }
                    $phases = TripPhase::query()->whereIn('id', $members->pluck('trip_phase_id'))->get()
                        ->keyBy('id');
                    $projects = $members->map(fn ($c) => $phases->get($c->trip_phase_id)?->project_id)->unique();
                    $teams = $members->map(fn ($c) => $phases->get($c->trip_phase_id)?->team_id)->unique();
                    abort_unless($projects->count() === 1 && $teams->count() === 1
                        && (int) ($row->resolutions['project_id'] ?? 0) === (int) $projects->first(), 422);
                    $finals = Invoice::query()->whereIn('id', $members->pluck('current_final_id')->filter())
                        ->whereIn('status', ['approved', 'sent', 'paid'])->orderBy('id')->lockForUpdate()->get();
                    $operating = $finals->count() === $members->count()
                        ? D::money(D::sum($finals->pluck('grand_total')->all())) : null;
                    $accounting = $row->normalized['AG'] ?? null;
                    if ($accounting !== null) {
                        D::money((string) $accounting);
                    }
                    $review = AccountingReview::query()->where('project_id', $projects->first())
                        ->where('team_id', $teams->first())->where('period_year', (int) $import->sheet)
                        ->lockForUpdate()->first();
                    abort_unless(! $review || (int) $review->version === (int) ($row->resolutions['review_version'] ?? 0), 409);
                    if (! $review) {
                        $review = new AccountingReview;
                    } else {
                        Gate::forUser($actor)->authorize('update', $review);
                    }
                    $review->fill([
                        'project_id' => $projects->first(), 'team_id' => $teams->first(),
                        'period_year' => (int) $import->sheet, 'operating_total' => $operating,
                        'accounting_total' => $accounting,
                        'difference' => $operating === null || $accounting === null ? null : D::money(D::sub($operating, $accounting)),
                        'note' => $row->resolutions['note'] ?? null,
                        'source' => ['case_ids' => $members->pluck('id')->all(), 'import_sha256' => $import->sha256,
                            'row' => $row->source_row, 'reported_income' => $row->normalized['AD'] ?? null,
                            'reported_operating' => $row->normalized['AE'] ?? null],
                        'reviewed_by_id' => $actor->id, 'reviewed_at' => now(),
                    ]);
                    if ($review->exists) {
                        $review->version++;
                    }
                    $review->save();
                    AuditLog::query()->create([
                        'user_id' => $actor->id, 'action' => 'financial_import_review_applied',
                        'module' => 'financial_imports', 'auditable_type' => AccountingReview::class,
                        'auditable_id' => $review->id,
                        'metadata' => ['import_id' => $import->id, 'row_id' => $row->id,
                            'case_ids' => $members->pluck('id')->all()],
                    ]);
                    $row->forceFill(['applied_at' => now()])->save();

                    continue;
                }
                $case = $cases->get($row->target_case_id);
                abort_unless($case && ($originalVersions[$case->id] ?? null) === (int) $row->expected_version, 409);
                $values = $row->normalized;
                $accountingOnly = $import->sheet === '2025' && ($values['V'] ?? null) !== null
                    && (($values['M'] ?? null) === null || D::compare((string) $values['M'], '0') === 0);
                $this->scope->authorize($actor, $accountingOnly ? 'accounting.manage' : 'payments.manage', (int) $case->trip_phase_id);
                $sourceStatus = trim((string) ($values['lifecycle_status'] ?? ''));
                $metadata = $case->metadata ?? [];
                $metadata['legacy_status_source'] = $sourceStatus;
                $metadata['implementation_date'] = $values['H'] ?? null;
                $metadata['draft_sent_by_name'] = $values['I'] ?? null;
                $metadata['legacy_send_initial'] = $values['D'] ?? null;
                $metadata['legacy_send_final'] = $values['E'] ?? null;
                $metadata['legacy_unlabelled_notes'] = $values['AA'] ?? null;
                $metadata['legacy_reported_metrics'] = array_intersect_key($values,
                    array_flip(['S', 'T', 'U', 'V', 'W', 'N', 'O', 'P']));
                $approvedStatus = $row->resolutions['lifecycle_status'] ?? null;
                if ($approvedStatus !== null) {
                    abort_unless($approvedStatus === $sourceStatus, 422);
                }
                if ($sourceStatus !== '' && $sourceStatus !== $case->lifecycle_status) {
                    abort_unless($approvedStatus === $sourceStatus
                        && ($metadata['calculation_version'] ?? null) === 'legacy-unverified'
                        && $actor->hasPermission('billing_cases.transition'), 422,
                        'El estado legado exige mapeo revisado sobre expediente migrado.');
                }
                $case->forceFill(['metadata' => $metadata,
                    'lifecycle_status' => $approvedStatus ?? $case->lifecycle_status,
                    'version' => $case->version + 1])->save();
                AuditLog::query()->create([
                    'user_id' => $actor->id, 'action' => 'financial_import_case_applied',
                    'module' => 'financial_imports', 'auditable_type' => BillingCase::class,
                    'auditable_id' => $case->id,
                    'metadata' => ['import_id' => $import->id, 'row_id' => $row->id,
                        'source_status' => $sourceStatus, 'applied_status' => $case->lifecycle_status],
                ]);
                if ($import->sheet === '2025') {
                    $legacyAmount = $values['M'] ?? null;
                    if ($legacyAmount !== null && D::compare((string) $legacyAmount, '0') > 0) {
                        $legacyDate = $row->resolutions['paid_on'] ?? null;
                        $legacyReference = $row->resolutions['reference'] ?? null;
                        $legacyMethod = $row->resolutions['method'] ?? null;
                        $legacyStage = $row->resolutions['stage'] ?? null;
                        abort_unless(is_string($legacyDate)
                            && preg_match('/^20\d{2}-\d{2}-\d{2}$/D', $legacyDate)
                            && is_string($legacyReference) && $legacyReference !== ''
                            && is_string($legacyMethod) && $legacyMethod !== ''
                            && in_array($legacyStage, ['initial', 'final'], true), 422,
                            'Pago legado requiere fecha, tramo, método y referencia comprobados.');
                        $this->payments->record($actor, $case, 'chapter_to_eia', $legacyStage, (string) $legacyAmount,
                            $legacyDate, $legacyReference, $legacyMethod, $row->resolutions['note'] ?? null);
                    }
                    $accounting = $values['V'] ?? null;
                    if ($accounting !== null && $accounting !== '') {
                        $this->scope->authorize($actor, 'accounting.manage', (int) $case->trip_phase_id);
                        $phase = TripPhase::query()->findOrFail($case->trip_phase_id);
                        $final = Invoice::query()->whereKey($case->current_final_id)
                            ->whereIn('status', ['approved', 'sent', 'paid'])->lockForUpdate()->first();
                        $operating = $final?->grand_total;
                        $review = AccountingReview::query()->where('project_id', $phase->project_id)
                            ->where('team_id', $phase->team_id)->where('period_year', 2025)
                            ->lockForUpdate()->first();
                        abort_unless(! $review || ($review->source['case_ids'] ?? []) === [$case->id], 409,
                            'La conciliación existente agrupa otros expedientes.');
                        abort_unless(! $review || (int) $review->version === (int) ($row->resolutions['review_version'] ?? 0), 409);
                        if (! $review) {
                            $review = new AccountingReview;
                        }
                        $review->fill([
                            'project_id' => $phase->project_id, 'team_id' => $phase->team_id,
                            'period_year' => 2025, 'operating_total' => $operating,
                            'accounting_total' => $accounting,
                            'difference' => $operating === null ? null : D::money(D::sub($operating, $accounting)),
                            'note' => $row->resolutions['note'] ?? null,
                            'source' => ['case_ids' => [$case->id], 'import_sha256' => $import->sha256,
                                'row' => $row->source_row, 'reported_income' => $values['S'] ?? null,
                                'reported_operating' => $values['T'] ?? null,
                                'reported_credit' => $values['U'] ?? null],
                            'reviewed_by_id' => $actor->id, 'reviewed_at' => now(),
                        ]);
                        if ($review->exists) {
                            $review->version++;
                        }
                        $review->save();
                        AuditLog::query()->create([
                            'user_id' => $actor->id, 'action' => 'financial_import_review_applied',
                            'module' => 'financial_imports', 'auditable_type' => AccountingReview::class,
                            'auditable_id' => $review->id,
                            'metadata' => ['import_id' => $import->id, 'row_id' => $row->id,
                                'case_ids' => [$case->id]],
                        ]);
                    }
                }
                if ($import->sheet === '2026') {
                    $amount = $values['T'] ?? null;
                    if ($amount !== null && D::compare((string) $amount, '0') > 0) {
                        $this->payments->record($actor, $case, 'chapter_to_eia', $row->resolutions['stage'] ?? null, (string) $amount,
                            $row->resolutions['paid_on'] ?? $values['U'] ?? null, $row->resolutions['reference'] ?? null,
                            $row->resolutions['method'] ?? null, $row->resolutions['note'] ?? null);
                    }
                    $expected = $values['W'] ?? null;
                    if ($expected !== null && $expected !== '') {
                        app(TreasuryService::class)->setExpectation($actor, $case->id, 'eia_to_fiea', [
                            'version' => $case->fresh()->version, 'stage' => $row->resolutions['fiea_stage'] ?? null,
                            'expected_amount' => (string) $expected, 'basis_invoice_id' => null,
                            'reason' => $row->resolutions['note'] ?? 'Expectativa revisada desde el control original',
                        ]);
                        $case->refresh();
                    }
                    $amount = $values['X'] ?? null;
                    if ($amount !== null && D::compare((string) $amount, '0') > 0) {
                        $this->payments->record($actor, $case, 'eia_to_fiea', $row->resolutions['fiea_stage'] ?? null, (string) $amount,
                            $row->resolutions['fiea_paid_on'] ?? $values['Y'] ?? null, $row->resolutions['fiea_reference'] ?? null,
                            $row->resolutions['fiea_method'] ?? $values['Z'] ?? null, $row->resolutions['note'] ?? null);
                    }
                }
                $row->forceFill(['applied_at' => now()])->save();
            }
            $pending = FinancialImportRow::query()->where('financial_import_id', $import->id)
                ->whereNull('applied_at')->exists();
            $import->forceFill(['status' => $pending ? 'partial' : 'applied',
                'applied_at' => $pending ? null : now()])->save();
        }, 3);
    }
}
