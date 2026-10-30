<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\BillingCase;
use App\Models\Invoice;

final class FinancialPeriod
{
    public function year(BillingCase $case, ?Invoice $invoice = null): ?int
    {
        $date = $invoice?->snapshot['metadata']['document_date'] ?? $case->metadata['document_date'] ?? null;
        if (is_string($date) && preg_match('/^(20\d{2})-\d{2}-\d{2}$/D', $date, $m)) {
            return (int) $m[1];
        }

        return $case->currentCode?->year ?? $case->codes->max('year') ?? $invoice?->issued_on?->year;
    }
}
