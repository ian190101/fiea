<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Decimal as D;

final class FinancialPrintLayout
{
    public const VERSION = 'excel-fiea-2026-01';

    /** Prepara columnas de impresión sin modificar ni recalcular los snapshots emitidos. */
    public function sections(array $snapshot): array
    {
        $current = $this->index($snapshot['lines'] ?? []);
        $initial = $this->index($snapshot['initial_snapshot']['lines'] ?? []);
        $sections = [];
        foreach (array_unique([...array_keys($current), ...array_keys($initial)]) as $key) {
            $line = $current[$key] ?? $initial[$key];
            $section = ($line['bucket'] ?? '') === 'own' ? 'Fees' : ($line['section'] ?? 'Miscellaneous');
            $sections[$section][] = ['line' => $line, 'initial' => $this->values($initial[$key] ?? null),
                'current' => $this->values($current[$key] ?? null),
                'receipt' => implode(', ', array_unique(array_filter([$current[$key]['receipt_number'] ?? null,
                    ...array_column($current[$key]['cells'] ?? [], 'receipt_number')])))];
        }
        foreach (['contingency', 'serviceFee', 'bankFee'] as $field) {
            if (! isset($snapshot['totals'][$field])) {
                continue;
            }
            $section = $field === 'contingency' ? 'Miscellaneous' : 'Fees';
            $label = match ($field) {
                'contingency' => 'Contingency (10%)',
                'serviceFee' => $snapshot['type'] === 'IC' ? 'Arranged Services Fee (5%)' : 'Material Purchase Fee (15%)',
                default => 'Bank Fees',
            };
            $amount = (string) $snapshot['totals'][$field];
            $prior = (string) ($snapshot['initial_snapshot']['totals'][$field] ?? '0');
            $sections[$section][] = ['line' => ['description_en' => $label, 'unit' => 'Each'],
                'current' => ['quantity' => '1', 'cost' => $amount, 'amount' => $amount],
                'initial' => ['quantity' => '1', 'cost' => $prior, 'amount' => $prior], 'receipt' => ''];
        }
        $order = ['Materials', 'Transportation', 'Services', 'Lodging', 'Food', 'Miscellaneous', 'Fees'];
        uksort($sections, static fn (string $a, string $b): int => (array_search($a, $order, true) === false ? 90 : array_search($a, $order, true))
            <=> (array_search($b, $order, true) === false ? 90 : array_search($b, $order, true)));

        return $sections;
    }

    public function subtotal(array $rows, string $stage): string
    {
        return D::money(D::sum(array_map(static fn (array $row): string => (string) $row[$stage]['amount'], $rows)));
    }

    private function index(array $lines): array
    {
        $result = [];
        foreach ($lines as $index => $line) {
            $key = isset($line['id']) ? 'id:'.$line['id'] : ($line['catalog_key'] ?? ($line['description_en'] ?? 'row').':'.$index);
            $result[$key] = $line;
        }

        return $result;
    }

    private function values(?array $line): array
    {
        return ['quantity' => $line['quantity'] ?? D::quantity(D::sum(array_column($line['cells'] ?? [], 'quantity'))),
            'cost' => (string) ($line['unit_cost'] ?? '0'), 'amount' => (string) ($line['raw_amount'] ?? '0')];
    }
}
