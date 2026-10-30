<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Data\Financial\UpdateBudgetData;
use App\Models\AuditLog;
use App\Models\BillingCase;
use App\Models\BudgetLine;
use App\Models\BudgetLineVersion;
use App\Models\Invoice;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\Security\ScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class BudgetService
{
    public function __construct(private readonly ScopeService $scope) {}

    public function update(User $actor, int $tripPhaseId, UpdateBudgetData $data): void
    {
        DB::transaction(function () use ($actor, $tripPhaseId, $data): void {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $phase = TripPhase::query()->lockForUpdate()->findOrFail($tripPhaseId);
            $case = BillingCase::query()->where('trip_phase_id', $phase->id)
                ->where('invoice_type', $data->invoiceType)->lockForUpdate()->firstOrFail();
            $permission = $data->stage === 'final' ? 'actual_expenses.manage' : 'budgets.manage';
            $this->scope->authorize($actor, $permission, (int) $phase->id);
            abort_if(in_array($case->lifecycle_status, ['CERRADO', 'ANULADO'], true) || $phase->status === 'cancelled', 409);
            abort_unless((int) $case->version === $data->expectedVersion, 409, 'La matriz cambió. Recarga antes de guardar.');
            $pointer = $data->stage === 'initial' ? 'current_initial_id' : 'current_final_id';
            if ($case->{$pointer} !== null) {
                abort_if(Invoice::query()->whereKey($case->{$pointer})
                    ->whereIn('status', ['approved', 'sent', 'paid'])->exists(), 409);
            }

            $lineIds = array_column($data->lines, 'id');
            $lines = BudgetLine::query()->where('billing_case_id', $case->id)
                ->whereIn('id', $lineIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($lines->count() === count($lineIds), 404);

            $before = [];
            $after = [];
            foreach ($data->lines as $change) {
                $line = $lines->get($change['id']);
                $version = BudgetLineVersion::query()->where('budget_line_id', $line->id)
                    ->where('stage', $data->stage)->lockForUpdate()->first();
                $before[] = ['line_id' => $line->id, 'values' => $version?->only(['unit_cost', 'quantity', 'receipt_number']),
                    'cells' => $version?->cells()->get(['spent_on', 'quantity', 'receipt_number'])->toArray() ?? []];
                if (! $version) {
                    $version = BudgetLineVersion::query()->create([
                        'budget_line_id' => $line->id, 'stage' => $data->stage,
                        'unit_cost' => $change['unit_cost'],
                        'quantity' => $data->invoiceType === 'MAT' ? $change['quantity'] : null,
                        'receipt_number' => $change['receipt_number'] ?? null,
                        'is_captured' => true,
                    ]);
                } else {
                    $version->fill([
                        'unit_cost' => $change['unit_cost'],
                        'quantity' => $data->invoiceType === 'MAT' ? $change['quantity'] : null,
                        'receipt_number' => $change['receipt_number'] ?? null,
                        'is_captured' => true,
                    ])->save();
                }

                $version->cells()->orderBy('id')->lockForUpdate()->get();
                foreach ($change['cells'] as $cell) {
                    $day = CarbonImmutable::createFromFormat('!Y-m-d', $cell['date']);
                    abort_unless($day && $day->toDateString() === $cell['date']
                        && $day->gte($phase->starts_on) && $day->lte($phase->ends_on), 422,
                        'La fecha de celda está fuera del viaje.');
                    $existing = $version->cells()->whereIn('spent_on', [$cell['date'], $cell['date'].' 00:00:00'])
                        ->lockForUpdate()->first();
                    $values = [
                        'quantity' => $cell['quantity'],
                        'receipt_number' => $cell['receipt_number'] ?? null,
                    ];
                    if ($existing) {
                        $existing->fill($values)->save();
                    } else {
                        $version->cells()->create(['spent_on' => $cell['date']] + $values);
                    }
                }
                $after[] = ['line_id' => $line->id, 'values' => $version->only(['unit_cost', 'quantity', 'receipt_number']),
                    'cells' => $version->cells()->get(['spent_on', 'quantity', 'receipt_number'])->toArray()];
            }
            $case->forceFill(['version' => $case->version + 1])->save();
            AuditLog::query()->create([
                'user_id' => $actor->id, 'action' => 'budget_matrix_updated', 'module' => 'budgets',
                'auditable_type' => BillingCase::class, 'auditable_id' => $case->id,
                'trip_phase_id' => $phase->id,
                'metadata' => ['trip_phase_id' => $phase->id, 'stage' => $data->stage,
                    'version' => $case->version, 'line_ids' => $lineIds, 'before' => $before, 'after' => $after],
            ]);
        }, 3);
    }

    public function addLine(User $actor, int $caseId, array $data): BudgetLine
    {
        return DB::transaction(function () use ($actor, $caseId, $data): BudgetLine {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $phaseId = BillingCase::query()->whereKey($caseId)->value('trip_phase_id');
            abort_unless($phaseId, 404);
            $phase = TripPhase::query()->lockForUpdate()->findOrFail($phaseId);
            $case = BillingCase::query()->lockForUpdate()->findOrFail($caseId);
            $this->scope->authorize($actor, 'budgets.manage', (int) $phase->id);
            $this->assertEditableCase($case, (int) $data['version'], true);
            $catalogKey = $data['catalog_key'] ?? null;
            if ($catalogKey !== null) {
                $item = collect(app(BudgetCatalog::class)->forType($case->invoice_type))
                    ->firstWhere('catalog_key', $catalogKey);
                abort_unless($item, 422, 'Artículo no autorizado para este tipo.');
                $classification = $item;
            } else {
                abort_unless($case->invoice_type === 'IC'
                    && ($data['section'] ?? null) === 'Miscellaneous'
                    && ($data['bucket'] ?? null) === 'dr'
                    && ($data['arranged_eligible'] ?? null) === true, 422);
                $classification = app(BudgetCatalog::class)->customMiscellaneous($data['description_en']);
            }
            $line = BudgetLine::query()->create([
                'billing_case_id' => $case->id, 'catalog_key' => $catalogKey,
                'description_en' => $case->invoice_type === 'MAT'
                    ? ($data['description_en'] ?? $classification['description_en'])
                    : ($catalogKey ? $classification['description_en'] : $data['description_en']),
                'description_es' => $data['description_es'] ?? $classification['description_es'],
                'unit' => $data['unit'], 'section' => $classification['section'],
                'bucket' => $classification['bucket'],
                'arranged_eligible' => $classification['arranged_eligible'],
                'sort_order' => (int) (BudgetLine::query()->where('billing_case_id', $case->id)->max('sort_order') ?? 0) + 1,
            ]);
            $initialFrozen = Invoice::query()->whereKey($case->current_initial_id)
                ->whereIn('status', ['approved', 'sent', 'paid'])->exists();
            foreach ($initialFrozen ? ['final'] : ['initial', 'final'] as $stage) {
                $version = $line->versions()->create(['stage' => $stage, 'unit_cost' => '0.0000',
                    'quantity' => $case->invoice_type === 'MAT' ? '0.0000' : null, 'is_captured' => false]);
                if ($case->invoice_type === 'IC') {
                    for ($day = $phase->starts_on->copy(); $day->lte($phase->ends_on); $day = $day->addDay()) {
                        $version->cells()->create(['spent_on' => $day->toDateString(), 'quantity' => '0.0000']);
                    }
                }
            }
            $case->forceFill(['version' => $case->version + 1])->save();
            AuditLog::query()->create([
                'user_id' => $actor->id, 'action' => 'budget_line_added', 'module' => 'budgets',
                'auditable_type' => BudgetLine::class, 'auditable_id' => $line->id,
                'metadata' => ['case_id' => $case->id, 'catalog_key' => $catalogKey],
            ]);

            return $line;
        }, 3);
    }

    public function deleteLine(User $actor, int $caseId, int $lineId, int $expectedVersion): void
    {
        DB::transaction(function () use ($actor, $caseId, $lineId, $expectedVersion): void {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $phaseId = BillingCase::query()->whereKey($caseId)->value('trip_phase_id');
            abort_unless($phaseId, 404);
            $phase = TripPhase::query()->lockForUpdate()->findOrFail($phaseId);
            $case = BillingCase::query()->lockForUpdate()->findOrFail($caseId);
            $this->scope->authorize($actor, 'budgets.manage', (int) $phase->id);
            $this->assertEditableCase($case, $expectedVersion);
            $line = BudgetLine::query()->where('billing_case_id', $case->id)
                ->lockForUpdate()->findOrFail($lineId);
            abort_unless(! $line->versions()->where('is_captured', true)->exists()
                && ! $line->legacy_actual_expense_id && ! $line->legacy_estimated_expense_id && ! $line->receipts()->exists(), 409,
                'La línea tiene evidencia y requiere exclusión en una nueva versión.');
            foreach ($line->versions()->lockForUpdate()->get() as $version) {
                $version->cells()->delete();
                $version->delete();
            }
            $line->delete();
            $case->forceFill(['version' => $case->version + 1])->save();
            AuditLog::query()->create([
                'user_id' => $actor->id, 'action' => 'budget_line_deleted', 'module' => 'budgets',
                'auditable_type' => BudgetLine::class, 'auditable_id' => $lineId,
                'metadata' => ['case_id' => $case->id],
            ]);
        }, 3);
    }

    private function assertEditableCase(BillingCase $case, int $expectedVersion, bool $finalAddition = false): void
    {
        abort_unless((int) $case->version === $expectedVersion, 409);
        abort_if($case->tripPhase->status === 'cancelled'
            || in_array($case->lifecycle_status, ['CERRADO', 'ANULADO'], true)
            || Invoice::query()->where('billing_case_id', $case->id)
                ->when($finalAddition, fn ($q) => $q->where('stage', 'final'))
                ->whereIn('status', ['approved', 'sent', 'paid'])->exists(), 409);
    }
}
