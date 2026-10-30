<?php
declare(strict_types=1);
namespace App\Services\Finance;
use App\Contracts\InvoiceCalculatorInterface;
use InvalidArgumentException;
final readonly class InvoiceCalculatorRegistry
{
    /** @param array<string, InvoiceCalculatorInterface> $calculators */
    public function __construct(private array $calculators) {}
    public function forType(string $type): InvoiceCalculatorInterface
    {
        return $this->calculators[$type] ?? throw new InvalidArgumentException('Tipo de invoice no registrado.');
    }
}
