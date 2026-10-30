<?php

declare(strict_types=1);

namespace App\Services\Receipts;

use App\Models\BudgetLine;
use App\Models\Invoice;
use App\Models\TripPhase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class ReceiptEditGuard
{
    public function check(TripPhase $phase, Collection $cases, Model $target, int $version): void
    {
        abort_unless((int) $phase->version === $version, 409);
        abort_if($phase->status === 'cancelled', 409);
        $relevant = $target instanceof BudgetLine ? $cases->where('id', $target->billing_case_id) : $cases;
        abort_if($relevant->contains(fn ($case) => in_array($case->lifecycle_status, ['CERRADO', 'ANULADO'], true)), 409);
        $invoices = Invoice::query()->where('trip_phase_id', $phase->id)->where('stage', 'final')->whereIn('status', ['approved', 'sent', 'paid']);
        if ($target instanceof BudgetLine) {
            $invoices->where('billing_case_id', $target->billing_case_id);
        }
        abort_if($invoices->exists(), 409);
    }
}
