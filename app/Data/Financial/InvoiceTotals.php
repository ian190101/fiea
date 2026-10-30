<?php
declare(strict_types=1);
namespace App\Data\Financial;
final readonly class InvoiceTotals
{
    public function __construct(
        public string $subtotal,
        public string $eligibleBase,
        public string $contingency,
        public string $serviceFee,
        public string $bankFee,
        public string $totalDr,
        public string $totalWodr,
        public string $total,
        public string $balanceDue,
    ) {}
}
