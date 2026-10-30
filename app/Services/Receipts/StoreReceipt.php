<?php

declare(strict_types=1);

namespace App\Services\Receipts;

use App\Models\ActualExpense;
use App\Models\BillingCase;
use App\Models\BudgetLine;
use App\Models\Receipt;
use App\Models\StorageFile;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\Security\AuditWriter;
use App\Services\Security\ScopeService;
use App\Services\UploadedFileStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class StoreReceipt
{
    public function __construct(private readonly UploadedFileStorageService $storage,
        private readonly ScopeService $scope) {}

    public function execute(User $actor, array $data, UploadedFile $file): Receipt
    {
        $targets = app(ReceiptTarget::class);
        $phaseId = $targets->phaseId($targets->resolve($data));
        $this->scope->authorize($actor, 'receipts.manage', $phaseId);
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $data, $file, $phaseId, &$stored): Receipt {
                $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $phase = TripPhase::query()->lockForUpdate()->findOrFail($phaseId);
                $cases = BillingCase::query()->where('trip_phase_id', $phaseId)
                    ->orderBy('id')->lockForUpdate()->get();
                $this->scope->authorize($lockedActor, 'receipts.manage', $phaseId);
                $targets = app(ReceiptTarget::class);
                $parent = $targets->resolve($data, true);
                abort_unless($targets->phaseId($parent) === $phaseId, 409);
                app(ReceiptEditGuard::class)->check($phase, $cases, $parent, (int) $data['version']);
                if ($parent instanceof BudgetLine) {
                    abort_unless($parent->versions()->where('stage', 'final')->where('is_captured', true)->exists(), 422,
                        'Captura primero la línea Final.');
                }
                $stored = $this->storage->store($file, 'receipts/'.$parent->id, $lockedActor);
                $receipt = Receipt::query()->create([
                    'actual_expense_id' => $parent instanceof ActualExpense ? $parent->id : null,
                    'budget_line_id' => $parent instanceof BudgetLine ? $parent->id : null,
                    'storage_file_id' => $stored->id,
                    'receipt_number' => $data['receipt_number'] ?? null,
                    'issued_on' => $data['issued_on'] ?? null, 'amount' => $data['amount'],
                ]);
                app(AuditWriter::class)->record($lockedActor, 'receipt_stored', $receipt, $phaseId,
                    [], ['amount' => $receipt->amount, 'receipt_number' => $receipt->receipt_number,
                        'issued_on' => $receipt->issued_on?->toDateString(),
                        'version' => $phase->version + 1],
                    (string) (request()->attributes->get('correlation_id') ?? Str::uuid()));
                $phase->forceFill(['version' => $phase->version + 1])->save();
                foreach ($cases as $case) {
                    $case->forceFill(['version' => $case->version + 1])->save();
                }

                return $receipt;
            }, 1);
        } catch (\Throwable $exception) {
            // La transacción SQL no revierte el objeto; se compensa fuera de ella.
            if ($stored instanceof StorageFile) {
                try {
                    Storage::disk($stored->diskName())->delete($stored->object_key);
                } catch (\Throwable $cleanup) {
                    report($cleanup);
                }
            }
            throw $exception;
        }
    }
}
