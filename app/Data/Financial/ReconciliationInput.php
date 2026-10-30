<?php
declare(strict_types=1);
namespace App\Data\Financial;
final readonly class ReconciliationInput
{
    public function __construct(
        public string $income,
        public ?string $operating,
        public ?string $accounting,
        public ?string $expectedFiea,
        public string $receivedFiea,
    ) {}
}
