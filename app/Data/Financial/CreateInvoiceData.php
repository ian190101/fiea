<?php
declare(strict_types=1);
namespace App\Data\Financial;

final readonly class CreateInvoiceData
{
    public function __construct(
        public int $caseId,
        public string $stage,
        public int $expectedVersion,
        public string $idempotencyKey,
        public ?int $contactPersonId,
    ) {}
}
