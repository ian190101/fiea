<?php
declare(strict_types=1);
namespace App\Policies;
use App\Models\AccountingReview;
use App\Models\BillingCase;
use App\Models\User;
use App\Services\Security\ScopeService;
final class AccountingReviewPolicy
{
    public function __construct(private readonly ScopeService $scope) {}
    public function view(User $user, AccountingReview $review): bool
    {
        return $user->hasAnyPermission(['accounting.view','accounting.manage'])
            && $this->allCasesVisible($user, $review);
    }
    public function update(User $user, AccountingReview $review): bool
    {
        return $user->hasPermission('accounting.manage')
            && $this->allCasesVisible($user, $review);
    }
    private function allCasesVisible(User $user, AccountingReview $review): bool
    {
        $caseIds = $review->source['case_ids'] ?? [];
        if (!is_array($caseIds) || $caseIds === []) return false;
        $cases = BillingCase::query()->whereIn('id', $caseIds)->get(['id','trip_phase_id']);
        if ($cases->count() !== count(array_unique($caseIds))) return false;
        foreach ($cases as $case) {
            if (!$this->scope->permits($user, (int) $case->trip_phase_id)) return false;
        }
        return true;
    }
}
