<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\AccountingReview;
use App\Models\BillingCase;
use App\Models\User;
use Illuminate\Support\Collection;

final class AccountingReviewScope
{
    public function visible(User $actor, Collection $reviews): Collection
    {
        $ids = $reviews->flatMap(fn (AccountingReview $r): array => is_array($r->source['case_ids'] ?? null) ? $r->source['case_ids'] : [])->unique()->all();
        $visible = BillingCase::query()->whereIn('id', $ids)->whereIn('trip_phase_id', app(ScopeService::class)
            ->tripPhases($actor)->select('trip_phases.id'))->pluck('id')->map(fn ($id): int => (int) $id)->flip();

        return $reviews->filter(function (AccountingReview $review) use ($visible): bool {
            $members = $review->source['case_ids'] ?? [];

            return is_array($members) && $members !== [] && collect($members)->every(fn ($id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0 && $visible->has((int) $id));
        });
    }
}
