<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\AccountingReview;
use App\Models\AuditLog;
use App\Models\BillingCase;
use App\Models\Invoice;
use App\Models\TripPhase;
use App\Models\User;
use App\Support\Decimal as D;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AccountingReviewService
{
    public function update(User $actor, int $reviewId, int $expectedVersion, ?string $accountingTotal, string $note): void
    {
        DB::transaction(function () use ($actor, $reviewId, $expectedVersion, $accountingTotal, $note): void {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $source = AccountingReview::query()->whereKey($reviewId)->value('source');
            $caseIds = is_array($source) ? ($source['case_ids'] ?? []) : (json_decode((string) $source, true)['case_ids'] ?? []);
            abort_unless(is_array($caseIds) && $caseIds !== [], 422);
            $cases = BillingCase::query()->whereIn('id', $caseIds)->orderBy('id')->get();
            abort_unless($cases->count() === count(array_unique($caseIds)), 409);
            TripPhase::query()->whereIn('id', $cases->pluck('trip_phase_id'))
                ->orderBy('id')->lockForUpdate()->get();
            $cases = BillingCase::query()->whereIn('id', $caseIds)->orderBy('id')->lockForUpdate()->get();
            $review = AccountingReview::query()->lockForUpdate()->findOrFail($reviewId);
            Gate::forUser($actor)->authorize('update', $review);
            abort_unless((int) $review->version === $expectedVersion, 409);
            if ($accountingTotal !== null) {
                D::money($accountingTotal);
            }
            $finals = Invoice::query()->whereIn('id', $cases->pluck('current_final_id')->filter())
                ->whereIn('status', ['approved', 'sent', 'paid'])->orderBy('id')->lockForUpdate()->get();
            $operating = $finals->count() === $cases->count()
                ? D::money(D::sum($finals->pluck('grand_total')->all())) : null;
            $before = $review->only(['operating_total', 'accounting_total', 'difference', 'note', 'version']);
            $review->forceFill([
                'operating_total' => $operating,
                'accounting_total' => $accountingTotal,
                'difference' => $operating === null || $accountingTotal === null
                    ? null : D::money(D::sub($operating, $accountingTotal)),
                'note' => $note, 'version' => $review->version + 1,
                'reviewed_by_id' => $actor->id, 'reviewed_at' => now(),
            ])->save();
            AuditLog::query()->create([
                'user_id' => $actor->id, 'action' => 'accounting_review_updated',
                'module' => 'accounting', 'auditable_type' => AccountingReview::class,
                'auditable_id' => $review->id,
                'trip_phase_id' => $cases->pluck('trip_phase_id')->unique()->count() === 1 ? $cases->first()->trip_phase_id : null,
                'metadata' => ['before' => $before, 'after' => $review->only(array_keys($before))],
            ]);
        }, 3);
    }
}
