<?php
declare(strict_types=1);
namespace App\Services\Finance;
use App\Data\Financial\InvoiceTotals;
final class FinancialPresenter
{
    public function totals(InvoiceTotals $totals, string $type, ?string $credit): array
    {
        return [
            'total_dr' => $totals->totalDr,
            'total_wodr' => $totals->totalWodr,
            'grand_total' => $totals->total,
            'balance_due' => $totals->balanceDue,
            $type === 'IC' ? 'arranged_fee' : 'material_fee' => $totals->serviceFee,
            'bank_fee' => $totals->bankFee,
            'contingency' => $totals->contingency,
            'credit' => $credit,
        ];
    }

    public function budget(array $budget, string $view): array
    {
        return [
            ...$budget,
            'view' => $view,
            'sheets' => ['Draft Budget', 'Initial Invoice', 'Final Invoice'],
            'editors' => collect(['IC', 'MAT'])->flatMap(fn (string $type) =>
                collect(['initial', 'final'])->map(fn (string $stage) => [
                    'type' => $type, 'stage' => $stage, 'view' => 'draft',
                ]))->all(),
        ];
    }

    public function invoice(array $snapshot): array
    {
        return [
            'invoicePreview' => [
                'metadata' => $snapshot['metadata'] ?? [],
                'lines' => $snapshot['lines'] ?? [],
                'initial_lines' => $snapshot['initial_snapshot']['lines'] ?? null,
                'totals' => $snapshot['totals'] ?? [],
                'payment_terms' => $snapshot['payment_terms'] ?? [],
                'display_rounding_adjustment' => $snapshot['display_rounding_adjustment'] ?? [],
            ],
        ];
    }
}
