<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use App\Services\Finance\ControlQuery;

final class FinancialReportQuery
{
    public function __construct(private readonly ControlQuery $control) {}

    public function for(User $actor, array $filters): array
    {
        abort_unless($actor->hasPermission('reports.view'), 403);
        $page = $this->control->forYearBasis($actor, $filters['year'],
            $filters['cursor'], $filters['basis']);
        $accounts = [];
        $projects = array_map(function (array $group) use (&$accounts): array {
            if ($group['credit_account_id'] !== null) {
                $accounts[$group['credit_account_id']] = [
                    'id' => $group['credit_account_id'], 'team_id' => $group['team_id'], 'balance' => $group['credit']];
            }
            $group['shared_credit'] = $group['credit'];
            $group['credit'] = null;

            return $group;
        }, $page['group_totals']);

        return [
            'filters' => $filters,
            'summary' => $page['summary'] + ['partial' => $page['next_cursor'] !== null],
            'byProject' => $projects, 'creditAccounts' => array_values($accounts),
            'byFund' => [
                ['fund_type' => 'DR', 'actual_total' => $page['summary']['operating_dr']],
                ['fund_type' => 'WODR', 'actual_total' => $page['summary']['operating_wodr']],
            ],
            'invoiceStatus' => [], 'receiptCoverage' => null,
            'controlRows' => $page['rows'], 'nextCursor' => $page['next_cursor'],
        ];
    }
}
