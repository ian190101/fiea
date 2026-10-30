<?php
declare(strict_types=1);
namespace App\Data\Financial;
final readonly class ControlImportRow
{
    public function __construct(
        public string $section,
        public int $sourceRow,
        public array $raw,
        public array $normalized,
        public array $errors,
    ) {}
}
