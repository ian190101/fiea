<?php

namespace App\Http\Controllers;

use App\Models\ActualExpense;
use App\Models\AuditLog;
use App\Models\EstimatedExpense;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\SystemSetting;
use App\Models\Team;
use App\Models\TripPhase;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'metrics' => Inertia::defer(fn () => $this->payload()['metrics'], 'dashboard'),
            'financials' => Inertia::defer(fn () => $this->payload()['financials'], 'dashboard'),
            'invoiceStatus' => Inertia::defer(fn () => $this->payload()['invoiceStatus'], 'dashboard'),
            'accountingStatus' => Inertia::defer(fn () => $this->payload()['accountingStatus'], 'dashboard'),
            'upcomingTrips' => Inertia::defer(fn () => $this->payload()['upcomingTrips'], 'dashboard'),
            'attentionItems' => Inertia::defer(fn () => $this->payload()['attentionItems'], 'dashboard'),
            'recentActivity' => Inertia::defer(fn () => $this->payload()['recentActivity'], 'dashboard'),
            'systemRules' => $this->systemRules(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return $this->remember('payload', 10, function () {
            return [
                'metrics' => $this->metrics(),
                'financials' => $this->financials(),
                'invoiceStatus' => $this->invoiceStatus(),
                'accountingStatus' => $this->accountingStatus(),
                'upcomingTrips' => $this->upcomingTrips(),
                'attentionItems' => $this->attentionItems(),
                'recentActivity' => $this->recentActivity(),
            ];
        });
    }

    /**
     * @return array{lockFinalInvoiceByDefault: bool, accountingCanEditSummary: bool}
     */
    private function systemRules(): array
    {
        return $this->remember('system-rules', 60, function () {
            $settings = SystemSetting::query()->firstOrCreate(['id' => 1]);

            return [
                'lockFinalInvoiceByDefault' => $settings->lock_final_invoice_by_default,
                'accountingCanEditSummary' => $settings->accounting_can_edit_summary,
            ];
        });
    }

    /**
     * @return array<string, int|float>
     */
    private function metrics(): array
    {
        return [
            'open_invoices' => Invoice::query()->whereNotIn('status', ['paid', 'void'])->count(),
            'team_credits' => round((float) Team::query()->sum('credit_balance'), 2),
            'expenses_without_receipts' => ActualExpense::query()->doesntHave('receipts')->count(),
            'receipts' => Receipt::query()->count(),
            'pending_accounting' => Invoice::query()->where('accounting_status', 'pending')->count(),
            'active_trips' => TripPhase::query()->whereIn('status', ['scheduled', 'in_progress'])->count(),
        ];
    }

    /**
     * @return array<string, float>
     */
    private function financials(): array
    {
        $estimated = (float) EstimatedExpense::query()->sum('estimated_total');
        $real = (float) ActualExpense::query()->sum('real_total');

        return [
            'estimated_total' => round($estimated, 2),
            'real_total' => round($real, 2),
            'variance' => round($real - $estimated, 2),
            'invoice_total' => round((float) Invoice::query()->sum('grand_total'), 2),
            'balance_conciliation' => round((float) Invoice::query()->sum('balance_conciliation'), 2),
        ];
    }

    /**
     * @return array<int, array{status: string, count: int}>
     */
    private function invoiceStatus(): array
    {
        return Invoice::query()
            ->select('status')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->map(fn (Invoice $invoice) => [
                'status' => $invoice->status,
                'count' => (int) $invoice->count,
            ])
            ->all();
    }

    /**
     * @return array<int, array{status: string, count: int}>
     */
    private function accountingStatus(): array
    {
        return Invoice::query()
            ->select('accounting_status')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('accounting_status')
            ->orderBy('accounting_status')
            ->get()
            ->map(fn (Invoice $invoice) => [
                'status' => $invoice->accounting_status,
                'count' => (int) $invoice->count,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function upcomingTrips(): array
    {
        return TripPhase::query()
            ->with(['project:id,code,name', 'team:id,name'])
            ->whereDate('starts_on', '>=', now()->toDateString())
            ->orderBy('starts_on')
            ->limit(5)
            ->get(['id', 'project_id', 'team_id', 'phase', 'starts_on', 'ends_on', 'status'])
            ->map(fn (TripPhase $phase) => [
                'id' => $phase->id,
                'project_code' => $phase->project?->code,
                'project_name' => $phase->project?->name,
                'team_name' => $phase->team?->name,
                'phase' => $phase->phase,
                'starts_on' => $phase->starts_on?->toDateString(),
                'ends_on' => $phase->ends_on?->toDateString(),
                'status' => $phase->status,
            ])
            ->all();
    }

    /**
     * @return array<int, array{label: string, value: int, route: string, tone: string}>
     */
    private function attentionItems(): array
    {
        return [
            [
                'label' => 'Invoices pendientes de aprobacion',
                'value' => Invoice::query()->where('status', 'draft')->count(),
                'route' => 'invoices.index',
                'tone' => 'primary',
            ],
            [
                'label' => 'Invoices observados por contabilidad',
                'value' => Invoice::query()->where('accounting_status', 'flagged')->count(),
                'route' => 'accounting.index',
                'tone' => 'danger',
            ],
            [
                'label' => 'Gastos reales sin comprobante',
                'value' => ActualExpense::query()->doesntHave('receipts')->count(),
                'route' => 'actual-expenses.index',
                'tone' => 'accent',
            ],
            [
                'label' => 'PDFs de invoice pendientes',
                'value' => Invoice::query()->whereNull('pdf_file_id')->count(),
                'route' => 'invoices.index',
                'tone' => 'secondary',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentActivity(): array
    {
        return AuditLog::query()
            ->with('user:id,name,username')
            ->latest()
            ->limit(6)
            ->get(['id', 'user_id', 'action', 'module', 'created_at'])
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'module' => $log->module,
                'action' => $log->action,
                'user' => $log->user?->name ?? 'Sistema',
                'created_at' => $log->created_at?->toISOString(),
            ])
            ->all();
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function remember(string $key, int $seconds, callable $callback): mixed
    {
        if (app()->environment('testing')) {
            return $callback();
        }

        return Cache::remember("fiea:dashboard:{$key}", now()->addSeconds($seconds), $callback);
    }
}
