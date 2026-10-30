<?php
declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Services\Security\ScopeService;

final class InvoicePolicy
{
    public function __construct(private readonly ScopeService $scope) {}

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->hasAnyPermission(['invoices.view', 'invoices.manage'])
            && $this->scope->permits($user, (int) $invoice->trip_phase_id);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->hasPermission('invoices.manage')
            && $this->scope->permits($user, (int) $invoice->trip_phase_id);
    }

    public function approve(User $user, Invoice $invoice): bool
    {
        return $user->hasPermission('invoices.approve')
            && $this->scope->permits($user, (int) $invoice->trip_phase_id)
            && (int) $invoice->created_by_id !== (int) $user->id;
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $user->hasPermission('invoices.void')
            && $this->scope->permits($user, (int) $invoice->trip_phase_id);
    }
}
