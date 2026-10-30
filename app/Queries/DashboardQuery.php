<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\ActualExpense;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\Finance\ControlQuery;
use App\Services\Security\ScopeService;
use Illuminate\Database\Eloquent\Builder;

final class DashboardQuery
{
    public function __construct(private readonly ScopeService $scope,
        private readonly ControlQuery $control) {}

    public function for(User $actor): array
    {
        $props = [];
        $metrics = [];
        $attention = [];
        $canInvoice = $actor->hasAnyPermission(['invoices.view', 'invoices.manage']);
        $canActual = $actor->hasAnyPermission(['actual_expenses.view', 'actual_expenses.manage']);
        if ($canInvoice) {
            $metrics['open_invoices'] = $this->invoices($actor)->whereNotIn('status', ['paid', 'void'])->count();
            $props['invoiceStatus'] = $this->invoices($actor)->select('status')
                ->selectRaw('COUNT(*) as count')->groupBy('status')->orderBy('status')->get()
                ->map(fn (Invoice $row) => ['status' => $row->status, 'count' => (int) $row->count])->all();
        }
        if ($canActual) {
            $metrics['expenses_without_receipts'] = $this->actual($actor)
                ->doesntHave('receipts')->count();
        }
        if ($actor->hasAnyPermission(['receipts.view', 'receipts.manage'])) {
            $metrics['receipts'] = Receipt::query()->whereHas('actualExpense',
                fn (Builder $q) => $this->scope->constrain($q, $actor, 'actual_expenses.trip_phase_id'))->count();
        }
        if ($actor->hasPermission('trip_phases.view')) {
            $metrics['active_trips'] = $this->scope->tripPhases($actor)
                ->whereIn('status', ['scheduled', 'in_progress'])->count();
            $props['upcomingTrips'] = $this->scope->tripPhases($actor)
                ->with(['project:id,code,name', 'team:id,name'])
                ->whereDate('starts_on', '>=', now()->toDateString())
                ->whereNotIn('status', ['cancelled', 'completed'])
                ->orderBy('starts_on')->orderBy('id')->limit(5)
                ->get(['id', 'project_id', 'team_id', 'phase', 'starts_on', 'ends_on', 'status'])
                ->map(fn (TripPhase $phase) => [
                    'id' => $phase->id, 'project_code' => $phase->project?->code,
                    'project_name' => $phase->project?->name, 'team_name' => $phase->team?->name,
                    'phase' => $phase->phase, 'starts_on' => $phase->starts_on?->toDateString(),
                    'ends_on' => $phase->ends_on?->toDateString(), 'status' => $phase->status,
                ])->all();
        }
        if ($actor->hasAnyPermission(['reports.view', 'accounting.view'])) {
            $summary = $this->control->forYear($actor, (int) date('Y'), null)['summary'];
            $props['financials'] = [
                'estimated_total' => null, 'real_total' => $summary['operating_total'],
                'variance' => $summary['difference'], 'invoice_total' => $summary['operating_total'],
                'balance_conciliation' => null, 'income_real' => $summary['income'],
                'credit_confirmed' => $summary['credit'],
            ];
            $accounting = app(AccountingQuery::class)->summary($actor, null);
            $props['accountingStatus'] = array_map(fn (string $status): array => ['status' => $status, 'count' => $accounting[$status]],
                ['pending', 'reconciled', 'flagged']);
        }
        if ($actor->hasPermission('audit_logs.view')) {
            $props['recentActivity'] = AuditLog::query()->where('user_id', $actor->id)
                ->with('user:id,name,username')->orderByDesc('created_at')->orderByDesc('id')
                ->limit(6)->get(['id', 'user_id', 'action', 'module', 'created_at'])
                ->map(fn (AuditLog $log) => ['id' => $log->id, 'module' => $log->module,
                    'action' => $log->action, 'user' => $log->user?->name ?? 'Sistema',
                    'created_at' => $log->created_at?->toISOString()])->all();
        }
        if ($actor->hasPermission('settings.manage')) {
            $props['systemRules'] = [
                'lockFinalInvoiceByDefault' => true,
                'accountingCanEditSummary' => false,
            ];
        }
        if ($actor->hasPermission('invoices.manage')) {
            $attention[] = [
                'label' => 'Invoices pendientes de aprobación',
                'value' => $this->invoices($actor)->where('status', 'draft')->count(),
                'route' => 'invoices.index', 'tone' => 'primary'];
        }
        if ($actor->hasPermission('accounting.view')) {
            $attention[] = [
                'label' => 'Invoices observados por contabilidad',
                'value' => app(AccountingQuery::class)->summary($actor, null)['flagged'],
                'route' => 'accounting.index', 'tone' => 'danger'];
        }
        if ($canActual) {
            $attention[] = [
                'label' => 'Gastos reales sin comprobante',
                'value' => $this->actual($actor)->doesntHave('receipts')->count(),
                'route' => 'actual-expenses.index', 'tone' => 'accent'];
        }
        if ($attention !== []) {
            $props['attentionItems'] = $attention;
        }
        if ($metrics !== []) {
            $props['metrics'] = $metrics;
        }

        return $props;
    }

    private function invoices(User $actor): Builder
    {
        return $this->scope->constrain(Invoice::query(), $actor, 'invoices.trip_phase_id');
    }

    private function actual(User $actor): Builder
    {
        return $this->scope->constrain(ActualExpense::query(), $actor, 'actual_expenses.trip_phase_id');
    }
}
