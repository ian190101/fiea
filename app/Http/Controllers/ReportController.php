<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ReportFilterRequest;
use App\Queries\FinancialReportQuery;
use App\Services\Finance\ControlQuery;
use App\Support\CsvCell;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportController extends Controller
{
    public function index(ReportFilterRequest $request, FinancialReportQuery $query): Response
    {
        return Inertia::render('Reports/Index', $query->for($request->user(), $request->filters()));
    }

    public function export(ReportFilterRequest $request, ControlQuery $query): StreamedResponse
    {
        $filter = $request->filters();

        return response()->streamDownload(function () use ($request, $query, $filter): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['billing_case_id', 'group_key', 'project_code', 'project_name', 'invoice_codes', 'income_real',
                'final_total', 'accounting_total', 'difference', 'credit_confirmed', 'basis', 'credit_account_id', 'credit_scope', 'operating_less_accounting']);
            $cursor = null;
            $seenAccounts = [];
            do {
                $page = $query->rowsForYear($request->user(), $filter['year'], $cursor, $filter['basis']);
                foreach ($page['rows'] as $row) {
                    $account = $row['credit_account_id'];
                    // El saldo pertenece al equipo: se publica una sola vez, nunca como reparto por expediente.
                    $credit = $account !== null && ! isset($seenAccounts[$account]) ? $row['credit'] : null;
                    if ($account !== null) {
                        $seenAccounts[$account] = true;
                    }
                    fputcsv($handle, [
                        $row['billing_case_id'], $row['group_key'],
                        CsvCell::text($row['project_code']), CsvCell::text($row['project_name']),
                        implode(' & ', $row['invoice_codes']),
                        $row['eia']['received'], $row['operating_total'],
                        $row['accounting_total'], $row['case_cash_surplus'], $credit, $page['basis'],
                        $account, $account === null ? '' : 'team_account_once_unallocated', $row['difference'],
                    ]);
                }
                $cursor = $page['next_cursor'];
            } while ($cursor !== null);
            fclose($handle);
        }, 'fiea-control-'.$filter['year'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
