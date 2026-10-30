<?php
declare(strict_types=1);
namespace App\Data\Financial;
final readonly class RecordPaymentData
{
    public function __construct(
        public int $caseId,
        public ?int $invoiceId,
        public string $leg,
        public string $stage,
        public string $direction,
        public string $amount,
        public string $paidOn,
        public string $method,
        public ?string $reference,
        public ?string $note,
        public string $idempotencyKey,
        public int $expectedCaseVersion,
    ) {}
}
