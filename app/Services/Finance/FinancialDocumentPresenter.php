<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Support\Decimal as D;

final class FinancialDocumentPresenter
{
    public function totals(?array $totals, string $type): array
    {
        if ($totals === null) {
            return [];
        }
        $names = ['totalDr' => 'total_dr', 'totalWodr' => 'total_wodr', 'contingency' => 'contingency',
            'bankFee' => 'bank_fee', 'total' => 'grand_total', 'balanceDue' => 'balance_due'];
        $result = [];
        foreach ($names as $from => $to) {
            $result[$to] = $totals[$from] ?? null;
        }
        $result[$type === 'IC' ? 'arranged_fee' : 'material_fee'] = $totals['serviceFee'] ?? null;

        return $result;
    }

    public function snapshot(array $snapshot, ?string $code = null): array
    {
        $lines = fn (array $items): array => array_map(static function (array $line): array {
            $line['quantity'] = $line['quantity'] ?? D::quantity(D::sum(array_column($line['cells'] ?? [], 'quantity')));
            $line['total'] = D::money($line['raw_amount'] ?? '0');
            $line['receipts'] = array_values(array_unique(array_filter([
                $line['receipt_number'] ?? null, ...array_column($line['cells'] ?? [], 'receipt_number')])));

            return $line;
        }, $items);
        $terms = $snapshot['payment_terms'] ?? [];

        return ['metadata' => $snapshot['metadata'], 'code' => $code, 'lines' => $lines($snapshot['lines']),
            'initial_lines' => isset($snapshot['initial_snapshot']) ? $lines($snapshot['initial_snapshot']['lines'] ?? []) : null,
            'totals' => $this->totals($snapshot['totals'], $snapshot['type']),
            'legal_notes' => array_values(array_filter([$terms['lead_time'] ?? null, $terms['check'] ?? null])),
            'payment_methods' => array_map(static fn (string $method): array => ['label' => $method, 'description' => $method], $terms['methods'] ?? [])];
    }
}
