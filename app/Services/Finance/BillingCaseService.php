<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\BillingCase;
use App\Models\Invoice;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\Security\ScopeService;
use App\Support\Decimal as D;
use Illuminate\Support\Facades\DB;

final class BillingCaseService
{
    public function __construct(private readonly ScopeService $scope) {}

    public function create(User $actor, int $phaseId, array $data): BillingCase
    {
        return DB::transaction(function () use ($actor, $phaseId, $data): BillingCase {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $phase = TripPhase::query()->lockForUpdate()->findOrFail($phaseId);
            $this->scope->authorize($actor, 'budgets.manage', (int) $phase->id);
            abort_if($phase->status === 'cancelled', 409, 'El viaje está cancelado.');
            abort_unless(in_array($data['invoice_type'], ['IC', 'MAT'], true)
                && in_array($data['billing_mode'], ['standard', 'final_only'], true), 422);
            abort_unless(! BillingCase::query()->where('trip_phase_id', $phase->id)
                ->where('invoice_type', $data['invoice_type'])->exists(), 409);
            $case = BillingCase::query()->create([
                'trip_phase_id' => $phase->id, 'invoice_type' => $data['invoice_type'],
                'billing_mode' => $data['billing_mode'], 'metadata' => $data['metadata'],
            ]);
            AuditLog::query()->create([
                'user_id' => $actor->id, 'action' => 'billing_case_created',
                'module' => 'billing_cases', 'auditable_type' => BillingCase::class,
                'auditable_id' => $case->id, 'trip_phase_id' => $phase->id,
                'metadata' => ['trip_phase_id' => $phase->id, 'type' => $case->invoice_type],
            ]);

            return $case;
        }, 3);
    }

    public function updateSettings(User $actor, int $caseId, int $expectedVersion, array $data): void
    {
        DB::transaction(function () use ($actor, $caseId, $expectedVersion, $data): void {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $phaseId = BillingCase::query()->whereKey($caseId)->value('trip_phase_id');
            abort_unless($phaseId, 404);
            $phase = TripPhase::query()->lockForUpdate()->findOrFail($phaseId);
            $case = BillingCase::query()->lockForUpdate()->findOrFail($caseId);
            $this->scope->authorize($actor, 'budgets.manage', (int) $phase->id);
            abort_unless((int) $case->version === $expectedVersion, 409);
            abort_if(in_array($case->lifecycle_status, ['CERRADO', 'ANULADO'], true), 409);
            foreach (['bank_fee_initial', 'bank_fee_final'] as $key) {
                abort_unless(D::compare($data[$key], '0') >= 0
                    && D::money($data[$key]) === $data[$key], 422);
            }
            $contingencyChanged = $data['final_contingency'] !== $case->final_contingency;
            if ($data['final_contingency'] !== null) {
                abort_unless(D::compare($data['final_contingency'], '0') >= 0
                    && D::money($data['final_contingency']) === $data['final_contingency'], 422);
            }
            if ($contingencyChanged) {
                abort_unless(mb_strlen(trim($data['reason'])) >= 10, 422);
            }
            $initial = $case->current_initial_id ? Invoice::query()->lockForUpdate()->find($case->current_initial_id) : null;
            $final = $case->current_final_id ? Invoice::query()->lockForUpdate()->find($case->current_final_id) : null;
            abort_if($initial && in_array($initial->status, ['approved', 'sent', 'paid'], true)
                && $case->bank_fee_initial !== $data['bank_fee_initial'], 409);
            abort_if($final && in_array($final->status, ['approved', 'sent', 'paid'], true)
                && ($case->bank_fee_final !== $data['bank_fee_final']
                    || $case->final_contingency !== $data['final_contingency']), 409);
            $before = $case->only(['bank_fee_initial', 'bank_fee_final', 'final_contingency']);
            $metadata = $case->metadata ?? [];
            if ($contingencyChanged) {
                $metadata['final_contingency_note'] = trim($data['reason']);
                $metadata['final_contingency_approved_by_id'] = (int) $actor->id;
            }
            $case->forceFill([
                'bank_fee_initial' => $data['bank_fee_initial'],
                'bank_fee_final' => $data['bank_fee_final'],
                'final_contingency' => $data['final_contingency'],
                'metadata' => $metadata, 'version' => $case->version + 1,
            ])->save();
            AuditLog::query()->create([
                'user_id' => $actor->id,
                'action' => $contingencyChanged ? 'final_contingency_approved' : 'billing_case_settings_updated',
                'module' => 'billing_cases', 'auditable_type' => BillingCase::class,
                'auditable_id' => $case->id,
                'metadata' => ['before' => $before, 'after' => $case->only(array_keys($before)),
                    'reason' => $data['reason']],
            ]);
        }, 3);
    }
}
