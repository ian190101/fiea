<?php

namespace App\Http\Controllers;

use App\Models\ActualExpense;
use App\Models\EstimatedExpense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Receipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('Reports/Index', [
            'filters' => $filters,
            'projects' => Inertia::defer(fn () => Project::query()->orderBy('code')->get(['id', 'code', 'name']), 'reports'),
            'summary' => Inertia::defer(fn () => $this->payload($filters)['summary'], 'reports'),
            'byProject' => Inertia::defer(fn () => $this->payload($filters)['byProject'], 'reports'),
            'byFund' => Inertia::defer(fn () => $this->payload($filters)['byFund'], 'reports'),
            'invoiceStatus' => Inertia::defer(fn () => $this->payload($filters)['invoiceStatus'], 'reports'),
            'receiptCoverage' => Inertia::defer(fn () => $this->payload($filters)['receiptCoverage'], 'reports'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $rows = $this->byProject($filters);

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Project Code', 'Project Name', 'Estimated Total', 'Actual Total', 'Variance', 'Invoice Total', 'Balance Conciliation']);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['project_code'],
                    $row['project_name'],
                    $row['estimated_total'],
                    $row['actual_total'],
                    $row['variance'],
                    $row['invoice_total'],
                    $row['balance_conciliation'],
                ]);
            }

            fclose($handle);
        }, 'fiea-financial-report.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * @return array{start_date: string|null, end_date: string|null, project_id: int|null}
     */
    private function filters(Request $request): array
    {
        $data = validator($request->query(), [
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ])->validate();

        return [
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'project_id' => isset($data['project_id']) ? (int) $data['project_id'] : null,
        ];
    }

    /**
     * @param array{start_date: string|null, end_date: string|null, project_id: int|null} $filters
     * @return array<string, mixed>
     */
    private function payload(array $filters): array
    {
        $cacheKey = md5(json_encode($filters));

        return $this->remember("payload:{$cacheKey}", 30, fn () => [
            'summary' => $this->summary($filters),
            'byProject' => $this->byProject($filters),
            'byFund' => $this->byFund($filters),
            'invoiceStatus' => $this->invoiceStatus($filters),
            'receiptCoverage' => $this->receiptCoverage($filters),
        ]);
    }

    /**
     * @param array{start_date: string|null, end_date: string|null, project_id: int|null} $filters
     * @return array<string, float|int>
     */
    private function summary(array $filters): array
    {
        $estimatedTotal = (float) $this->estimatedQuery($filters)->sum('estimated_expenses.estimated_total');
        $actualTotal = (float) $this->actualQuery($filters)->sum('actual_expenses.real_total');
        $invoiceQuery = $this->invoiceQuery($filters);

        return [
            'estimated_total' => round($estimatedTotal, 2),
            'actual_total' => round($actualTotal, 2),
            'variance' => round($actualTotal - $estimatedTotal, 2),
            'invoice_total' => round((float) (clone $invoiceQuery)->sum('invoices.grand_total'), 2),
            'balance_conciliation' => round((float) (clone $invoiceQuery)->sum('invoices.balance_conciliation'), 2),
            'invoice_count' => (clone $invoiceQuery)->count(),
            'receipt_amount' => round((float) $this->receiptQuery($filters)->sum('receipts.amount'), 2),
        ];
    }

    /**
     * @param array{start_date: string|null, end_date: string|null, project_id: int|null} $filters
     * @return array<int, array<string, float|int|string|null>>
     */
    private function byProject(array $filters): array
    {
        $projects = Project::query()
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('id', $projectId))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $estimated = $this->estimatedQuery($filters)
            ->select('trip_phases.project_id')
            ->selectRaw('SUM(estimated_expenses.estimated_total) as total')
            ->groupBy('trip_phases.project_id')
            ->pluck('total', 'trip_phases.project_id');

        $actual = $this->actualQuery($filters)
            ->select('trip_phases.project_id')
            ->selectRaw('SUM(actual_expenses.real_total) as total')
            ->groupBy('trip_phases.project_id')
            ->pluck('total', 'trip_phases.project_id');

        $invoices = $this->invoiceQuery($filters)
            ->select('trip_phases.project_id')
            ->selectRaw('SUM(invoices.grand_total) as invoice_total')
            ->selectRaw('SUM(invoices.balance_conciliation) as balance_conciliation')
            ->groupBy('trip_phases.project_id')
            ->get()
            ->keyBy('project_id');

        return $projects
            ->map(function (Project $project) use ($estimated, $actual, $invoices) {
                $estimatedTotal = (float) ($estimated[$project->id] ?? 0);
                $actualTotal = (float) ($actual[$project->id] ?? 0);
                $invoice = $invoices->get($project->id);

                return [
                    'project_id' => $project->id,
                    'project_code' => $project->code,
                    'project_name' => $project->name,
                    'estimated_total' => round($estimatedTotal, 2),
                    'actual_total' => round($actualTotal, 2),
                    'variance' => round($actualTotal - $estimatedTotal, 2),
                    'invoice_total' => round((float) ($invoice->invoice_total ?? 0), 2),
                    'balance_conciliation' => round((float) ($invoice->balance_conciliation ?? 0), 2),
                ];
            })
            ->filter(fn (array $row) => $row['estimated_total'] > 0 || $row['actual_total'] > 0 || $row['invoice_total'] > 0)
            ->values()
            ->all();
    }

    /**
     * @param array{start_date: string|null, end_date: string|null, project_id: int|null} $filters
     * @return array<int, array{fund_type: string, estimated_total: float, actual_total: float, variance: float}>
     */
    private function byFund(array $filters): array
    {
        $estimated = $this->estimatedQuery($filters)
            ->select('estimated_expenses.fund_type')
            ->selectRaw('SUM(estimated_expenses.estimated_total) as total')
            ->groupBy('estimated_expenses.fund_type')
            ->pluck('total', 'estimated_expenses.fund_type');

        $actual = $this->actualQuery($filters)
            ->select('actual_expenses.fund_type')
            ->selectRaw('SUM(actual_expenses.real_total) as total')
            ->groupBy('actual_expenses.fund_type')
            ->pluck('total', 'actual_expenses.fund_type');

        return collect(['DR', 'WODR'])
            ->map(function (string $fundType) use ($estimated, $actual) {
                $estimatedTotal = (float) ($estimated[$fundType] ?? 0);
                $actualTotal = (float) ($actual[$fundType] ?? 0);

                return [
                    'fund_type' => $fundType,
                    'estimated_total' => round($estimatedTotal, 2),
                    'actual_total' => round($actualTotal, 2),
                    'variance' => round($actualTotal - $estimatedTotal, 2),
                ];
            })
            ->all();
    }

    /**
     * @param array{start_date: string|null, end_date: string|null, project_id: int|null} $filters
     * @return array<int, array{status: string, count: int, total: float}>
     */
    private function invoiceStatus(array $filters): array
    {
        return $this->invoiceQuery($filters)
            ->select('invoices.status')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(invoices.grand_total) as total')
            ->groupBy('invoices.status')
            ->orderBy('invoices.status')
            ->get()
            ->map(fn (Invoice $invoice) => [
                'status' => $invoice->status,
                'count' => (int) $invoice->count,
                'total' => round((float) $invoice->total, 2),
            ])
            ->all();
    }

    /**
     * @param array{start_date: string|null, end_date: string|null, project_id: int|null} $filters
     * @return array{with_receipt: int, without_receipt: int}
     */
    private function receiptCoverage(array $filters): array
    {
        $actualExpenseQuery = $this->actualQuery($filters)->select('actual_expenses.id');
        $total = (clone $actualExpenseQuery)->count();
        $withReceipt = ActualExpense::query()
            ->whereIn('id', $actualExpenseQuery)
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('receipts')
                    ->whereColumn('receipts.actual_expense_id', 'actual_expenses.id');
            })
            ->count();

        return [
            'with_receipt' => $withReceipt,
            'without_receipt' => max($total - $withReceipt, 0),
        ];
    }

    /**
     * @param array{start_date: string|null, end_date: string|null, project_id: int|null} $filters
     */
    private function estimatedQuery(array $filters)
    {
        return EstimatedExpense::query()
            ->join('trip_phases', 'trip_phases.id', '=', 'estimated_expenses.trip_phase_id')
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('trip_phases.project_id', $projectId))
            ->when($filters['start_date'], fn ($query, $date) => $query->whereDate('trip_phases.starts_on', '>=', $date))
            ->when($filters['end_date'], fn ($query, $date) => $query->whereDate('trip_phases.starts_on', '<=', $date));
    }

    /**
     * @param array{start_date: string|null, end_date: string|null, project_id: int|null} $filters
     */
    private function actualQuery(array $filters)
    {
        return ActualExpense::query()
            ->join('trip_phases', 'trip_phases.id', '=', 'actual_expenses.trip_phase_id')
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('trip_phases.project_id', $projectId))
            ->when($filters['start_date'], fn ($query, $date) => $query->whereDate('trip_phases.starts_on', '>=', $date))
            ->when($filters['end_date'], fn ($query, $date) => $query->whereDate('trip_phases.starts_on', '<=', $date));
    }

    /**
     * @param array{start_date: string|null, end_date: string|null, project_id: int|null} $filters
     */
    private function invoiceQuery(array $filters)
    {
        return Invoice::query()
            ->join('trip_phases', 'trip_phases.id', '=', 'invoices.trip_phase_id')
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('trip_phases.project_id', $projectId))
            ->when($filters['start_date'], fn ($query, $date) => $query->whereDate('trip_phases.starts_on', '>=', $date))
            ->when($filters['end_date'], fn ($query, $date) => $query->whereDate('trip_phases.starts_on', '<=', $date));
    }

    /**
     * @param array{start_date: string|null, end_date: string|null, project_id: int|null} $filters
     */
    private function receiptQuery(array $filters)
    {
        return Receipt::query()
            ->join('actual_expenses', 'actual_expenses.id', '=', 'receipts.actual_expense_id')
            ->join('trip_phases', 'trip_phases.id', '=', 'actual_expenses.trip_phase_id')
            ->when($filters['project_id'], fn ($query, $projectId) => $query->where('trip_phases.project_id', $projectId))
            ->when($filters['start_date'], fn ($query, $date) => $query->whereDate('trip_phases.starts_on', '>=', $date))
            ->when($filters['end_date'], fn ($query, $date) => $query->whereDate('trip_phases.starts_on', '<=', $date));
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

        return Cache::remember("fiea:reports:{$key}", now()->addSeconds($seconds), $callback);
    }
}
