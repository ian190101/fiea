<?php

declare(strict_types=1);

namespace App\Actions\Receipts;

use App\Models\BillingCase;
use App\Models\OutboxEvent;
use App\Models\Receipt;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\Receipts\ReceiptEditGuard;
use App\Services\Receipts\ReceiptTarget;
use App\Services\Security\AuditWriter;
use App\Services\Security\ScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UpdateReceipt
{
    public function __construct(private readonly ScopeService $scope) {}

    public function execute(User $actor, Receipt $receipt, array $data): Receipt
    {
        return $this->mutate($actor, $receipt, $data, false);
    }

    public function delete(User $actor, Receipt $receipt, array $data): void
    {
        $this->mutate($actor, $receipt, $data, true);
    }

    private function mutate(User $actor, Receipt $receipt, array $data, bool $delete): Receipt
    {
        $targets = app(ReceiptTarget::class);
        $phaseId = $targets->phaseId($targets->resolve($receipt->only(['actual_expense_id', 'budget_line_id'])));

        return DB::transaction(function () use ($actor, $receipt, $data, $delete, $phaseId): Receipt {
            $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $phase = TripPhase::query()->lockForUpdate()->findOrFail($phaseId);
            $cases = BillingCase::query()->where('trip_phase_id', $phaseId)
                ->orderBy('id')->lockForUpdate()->get();
            $this->scope->authorize($lockedActor, 'receipts.manage', $phaseId);
            $locked = Receipt::query()->lockForUpdate()->findOrFail($receipt->id);
            $targets = app(ReceiptTarget::class);
            $target = $targets->resolve($locked->only(['actual_expense_id', 'budget_line_id']), true);
            abort_unless($targets->phaseId($target) === $phaseId, 409);
            app(ReceiptEditGuard::class)->check($phase, $cases, $target, (int) $data['version']);
            $before = $locked->only(['receipt_number', 'issued_on', 'amount']);
            if ($delete) {
                $fileId = $locked->storage_file_id;
                $locked->delete();
                OutboxEvent::query()->firstOrCreate(
                    ['dedup_key' => 'storage-delete:'.$fileId],
                    ['type' => 'storage-delete', 'aggregate_id' => $fileId,
                        'payload' => ['storage_file_id' => $fileId], 'status' => 'pending',
                        'available_at' => now()->addHour()],
                );
            } else {
                $locked->fill(collect($data)->except('version')->all())->save();
            }
            app(AuditWriter::class)->record($lockedActor,
                $delete ? 'receipt_deleted' : 'receipt_updated', $locked, $phaseId, $before,
                $delete ? ['version' => $phase->version + 1] : ($locked->only([
                    'receipt_number', 'issued_on', 'amount']) + ['version' => $phase->version + 1]),
                (string) (request()->attributes->get('correlation_id') ?? Str::uuid()));
            $phase->forceFill(['version' => $phase->version + 1])->save();
            foreach ($cases as $case) {
                $case->forceFill(['version' => $case->version + 1])->save();
            }

            return $locked;
        }, 3);
    }
}
