<?php
declare(strict_types=1);
namespace App\Policies;
use App\Models\BillingCase;
use App\Models\User;
use App\Services\Security\ScopeService;
final class BillingCasePolicy
{
    public function __construct(private readonly ScopeService $scope) {}
    public function view(User $user, BillingCase $case): bool
    {
        return $user->hasAnyPermission(['budgets.view','budgets.manage','accounting.view','accounting.manage'])
            && $this->scope->permits($user, (int) $case->trip_phase_id);
    }
    public function update(User $user, BillingCase $case): bool
    {
        return $user->hasPermission('budgets.manage')
            && $this->scope->permits($user, (int) $case->trip_phase_id)
            && !in_array($case->lifecycle_status, ['CERRADO','ANULADO'], true);
    }
    public function transition(User $user, BillingCase $case): bool
    {
        return $user->hasPermission('billing_cases.transition')
            && $this->scope->permits($user, (int) $case->trip_phase_id);
    }
    public function payment(User $user, BillingCase $case): bool
    {
        return $user->hasPermission('payments.manage')
            && $this->scope->permits($user, (int) $case->trip_phase_id);
    }
    public function credit(User $user, BillingCase $case): bool
    {
        return $user->hasPermission('credits.manage')
            && $this->scope->permits($user, (int) $case->trip_phase_id);
    }
}
