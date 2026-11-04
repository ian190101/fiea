<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\FinancialPrintLayout;
use PHPUnit\Framework\TestCase;

final class FinancialPrintLayoutTest extends TestCase
{
    public function test_comparacion_conserva_lineas_initial_ausentes_en_final_y_recibos_diarios(): void
    {
        $snapshot = ['type' => 'IC', 'stage' => 'final', 'totals' => [],
            'initial_snapshot' => ['lines' => [['id' => 1, 'description_en' => 'Transporte inicial',
                'section' => 'Transportation', 'unit_cost' => '10', 'quantity' => '2', 'raw_amount' => '20']]],
            'lines' => [['id' => 2, 'description_en' => 'Comida final', 'section' => 'Food', 'unit_cost' => '5',
                'quantity' => null, 'raw_amount' => '15', 'cells' => [
                    ['quantity' => '1', 'receipt_number' => 'REC-1'], ['quantity' => '2', 'receipt_number' => 'REC-2'],
                ]]]];
        $layout = new FinancialPrintLayout;
        $sections = $layout->sections($snapshot);
        self::assertSame('20.00', $layout->subtotal($sections['Transportation'], 'initial'));
        self::assertSame('0.00', $layout->subtotal($sections['Transportation'], 'current'));
        self::assertSame('15.00', $layout->subtotal($sections['Food'], 'current'));
        self::assertSame('REC-1, REC-2', $sections['Food'][0]['receipt']);
        self::assertSame('3', $sections['Food'][0]['current']['quantity']);
    }
}
