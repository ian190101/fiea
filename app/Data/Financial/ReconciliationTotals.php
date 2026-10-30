<?php
declare(strict_types=1);
namespace App\Data\Financial;
final readonly class ReconciliationTotals
{
    public function __construct(
        public ?string $incomeLessOperating,
        public ?string $operatingLessAccounting,
        public ?string $pendingFiea,
    ) {}
}
