<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountingController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status');
        $settings = SystemSetting::query()->firstOrCreate(['id' => 1]);

        return Inertia::render('Accounting/Index', [
            'invoices' => Inertia::defer(fn () => $this->invoices($status), 'accounting'),
            'summary' => Inertia::defer(fn () => $this->summary($status), 'accounting'),
            'filters' => [
                'status' => $status,
            ],
            'settings' => [
                'accountingCanEditSummary' => $settings->accounting_can_edit_summary,
            ],
            'importPreview' => Inertia::defer(fn () => $request->session()->get('accounting_import_preview'), 'accounting'),
        ]);
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        $settings = SystemSetting::query()->firstOrCreate(['id' => 1]);

        $rules = [
            'accounting_status' => ['required', 'string', Rule::in(['pending', 'reconciled', 'flagged'])],
            'accounting_note' => ['nullable', 'string', 'max:2000'],
        ];

        if ($settings->accounting_can_edit_summary) {
            $rules['total_dr'] = ['required', 'numeric', 'min:0', 'max:999999999.99'];
            $rules['total_wodr'] = ['required', 'numeric', 'min:0', 'max:999999999.99'];
            $rules['balance_conciliation'] = ['required', 'numeric', 'min:0', 'max:999999999.99'];
        }

        $data = $request->validate($rules);
        $before = $invoice->only([
            'accounting_status',
            'accounting_note',
            'total_dr',
            'total_wodr',
            'grand_total',
            'balance_conciliation',
        ]);

        DB::transaction(function () use ($request, $invoice, $settings, $data, $before) {
            $payload = [
                'accounting_status' => $data['accounting_status'],
                'accounting_note' => $data['accounting_note'] ?? null,
                'accounting_reviewed_by_id' => $request->user()?->id,
                'accounting_reviewed_at' => now(),
            ];

            if ($settings->accounting_can_edit_summary) {
                $payload['total_dr'] = round((float) $data['total_dr'], 2);
                $payload['total_wodr'] = round((float) $data['total_wodr'], 2);
                $payload['grand_total'] = round($payload['total_dr'] + $payload['total_wodr'], 2);
                $payload['balance_conciliation'] = round((float) $data['balance_conciliation'], 2);
            }

            $invoice->fill($payload)->save();

            AuditLog::query()->create([
                'user_id' => $request->user()?->id,
                'action' => $settings->accounting_can_edit_summary ? 'accounting_summary_updated' : 'accounting_reconciled',
                'module' => 'accounting',
                'auditable_type' => Invoice::class,
                'auditable_id' => $invoice->id,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'metadata' => [
                    'before' => $before,
                    'after' => $invoice->only([
                        'accounting_status',
                        'accounting_note',
                        'total_dr',
                        'total_wodr',
                        'grand_total',
                        'balance_conciliation',
                    ]),
                ],
            ]);
        });

        return back()->with('success', 'Revision contable guardada correctamente.');
    }

    /**
     * @return array{count: int, total_dr: float, total_wodr: float, grand_total: float, balance_conciliation: float, pending: int, reconciled: int, flagged: int}
     */
    private function summary(?string $status): array
    {
        return $this->remember('summary:'.($status ?: 'all'), 30, function () use ($status) {
        $baseQuery = Invoice::query()
            ->when($status, fn ($query) => $query->where('accounting_status', $status));

        $totals = (clone $baseQuery)
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(total_dr) as total_dr')
            ->selectRaw('SUM(total_wodr) as total_wodr')
            ->selectRaw('SUM(grand_total) as grand_total')
            ->selectRaw('SUM(balance_conciliation) as balance_conciliation')
            ->first();

        $statusCounts = Invoice::query()
            ->select('accounting_status')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('accounting_status')
            ->pluck('count', 'accounting_status');

        return [
            'count' => (int) ($totals->count ?? 0),
            'total_dr' => round((float) ($totals->total_dr ?? 0), 2),
            'total_wodr' => round((float) ($totals->total_wodr ?? 0), 2),
            'grand_total' => round((float) ($totals->grand_total ?? 0), 2),
            'balance_conciliation' => round((float) ($totals->balance_conciliation ?? 0), 2),
            'pending' => (int) ($statusCounts['pending'] ?? 0),
            'reconciled' => (int) ($statusCounts['reconciled'] ?? 0),
            'flagged' => (int) ($statusCounts['flagged'] ?? 0),
        ];
        });
    }

    private function invoices(?string $status)
    {
        return Invoice::query()
            ->with([
                'tripPhase:id,project_id,team_id,phase,starts_on,ends_on',
                'tripPhase.project:id,code,name',
                'tripPhase.team:id,name,credit_balance',
                'contactPerson:id,full_name,email',
                'accountingReviewedBy:id,name,username',
            ])
            ->when($status, fn ($query) => $query->where('accounting_status', $status))
            ->orderByDesc('created_at')
            ->cursorPaginate(25)
            ->withQueryString();
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

        return Cache::remember("fiea:accounting:{$key}", now()->addSeconds($seconds), $callback);
    }
}
