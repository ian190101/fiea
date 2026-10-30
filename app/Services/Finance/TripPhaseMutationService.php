<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\BillingCase;
use App\Models\BudgetCell;
use App\Models\Invoice;
use App\Models\Team;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\Security\ScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TripPhaseMutationService
{
    public function __construct(private readonly ScopeService $scope) {}

    public function create(User $actor, array $data): TripPhase
    {
        return DB::transaction(function () use ($actor, $data): TripPhase {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->hasPermission('trip_phases.manage'), 403);
            $this->validateTechnician($data['assigned_technician_id'] ?? null);
            if (! $actor->scope_all) {
                $projectGranted = DB::table('user_project_assignments')->where('user_id', $actor->id)
                    ->where('project_id', $data['project_id'])->exists();
                $chapterGranted = Team::query()->whereKey($data['team_id'])
                    ->whereIn('chapter_id', DB::table('user_chapter_assignments')
                        ->select('chapter_id')->where('user_id', $actor->id))->exists();
                abort_unless($projectGranted && $chapterGranted, 404);
            }

            return TripPhase::query()->create($data);
        }, 3);
    }

    public function update(User $actor, int $phaseId, array $data, int $expectedVersion): TripPhase
    {
        return DB::transaction(function () use ($actor, $phaseId, $data, $expectedVersion): TripPhase {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $phase = TripPhase::query()->lockForUpdate()->findOrFail($phaseId);
            $cases = BillingCase::query()->where('trip_phase_id', $phaseId)->orderBy('id')->lockForUpdate()->get();
            $this->scope->authorize($actor, 'trip_phases.manage', $phaseId);
            if ((int) ($data['assigned_technician_id'] ?? 0) !== (int) ($phase->assigned_technician_id ?? 0)) {
                $this->validateTechnician($data['assigned_technician_id'] ?? null);
            }
            abort_unless((int) $phase->version === $expectedVersion, 409);
            $parentsChanged = (int) $data['project_id'] !== (int) $phase->project_id
                || (int) $data['team_id'] !== (int) $phase->team_id;
            if ($parentsChanged && ! $actor->scope_all) {
                $projectGranted = DB::table('user_project_assignments')->where('user_id', $actor->id)
                    ->where('project_id', $data['project_id'])->exists();
                $chapterGranted = Team::query()->whereKey($data['team_id'])
                    ->whereIn('chapter_id', DB::table('user_chapter_assignments')
                        ->select('chapter_id')->where('user_id', $actor->id))->exists();
                abort_unless($projectGranted && $chapterGranted, 404);
            }
            $datesChanged = $data['starts_on'] !== $phase->starts_on->toDateString()
                || $data['ends_on'] !== $phase->ends_on->toDateString();
            if ($parentsChanged || $datesChanged) {
                abort_unless(! Invoice::query()->whereIn('billing_case_id', $cases->pluck('id'))
                    ->whereIn('status', ['approved', 'sent', 'paid'])->exists(), 409);
            }
            if ($datesChanged) {
                abort_unless(! BudgetCell::query()->whereHas('version.line', fn ($q) => $q->whereIn('billing_case_id', $cases->pluck('id')))
                    ->where(fn ($q) => $q->whereDate('spent_on', '<', $data['starts_on'])
                        ->orWhereDate('spent_on', '>', $data['ends_on']))
                    ->where('quantity', '!=', '0')->exists(), 422,
                    'Hay cantidades fuera del nuevo rango de fechas.');
                abort_if(BudgetCell::query()->whereHas('version.line', fn ($q) => $q->whereIn('billing_case_id', $cases->pluck('id')))
                    ->where(fn ($q) => $q->whereDate('spent_on', '<', $data['starts_on'])->orWhereDate('spent_on', '>', $data['ends_on']))
                    ->whereNotNull('receipt_number')->where('receipt_number', '!=', '')->exists(), 422,
                    'Hay referencias de comprobante fuera del nuevo rango de fechas.');
            }
            $phase->fill($data)->forceFill(['version' => $phase->version + 1])->save();

            return $phase;
        }, 3);
    }

    public function delete(User $actor, int $phaseId, int $expectedVersion): void
    {
        DB::transaction(function () use ($actor, $phaseId, $expectedVersion): void {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $phase = TripPhase::query()->lockForUpdate()->findOrFail($phaseId);
            $this->scope->authorize($actor, 'trip_phases.manage', $phaseId);
            abort_unless((int) $phase->version === $expectedVersion, 409);
            abort_unless(! BillingCase::query()->where('trip_phase_id', $phaseId)->exists(), 409);
            $phase->delete();
        }, 3);
    }

    private function validateTechnician(?int $id): void
    {
        if ($id === null) {
            return;
        }
        if (! User::query()->whereKey($id)->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('code', ['tecnico', 'superadmin']))->exists()) {
            throw ValidationException::withMessages(['assigned_technician_id' => 'Selecciona un técnico activo.']);
        }
    }
}
