<?php
declare(strict_types=1);

namespace App\Data\Financial;

final readonly class UpdateBudgetData
{
    /** @param list<array<string, mixed>> $lines */
    public function __construct(
        public string $invoiceType,
        public string $stage,
        public int $expectedVersion,
        public array $lines,
    ) {}
}
