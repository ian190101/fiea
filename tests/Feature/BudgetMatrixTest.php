<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\UpdateBudgetRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class BudgetMatrixTest extends TestCase
{
    public function test_valida_decimales_fechas_y_campos_prohibidos(): void
    {
        $request = new UpdateBudgetRequest();
        $valid = [
            'invoice_type' => 'IC', 'stage' => 'initial', 'version' => 1,
            'lines' => [['id' => 12, 'unit_cost' => '32.5000', 'quantity' => null,
                'cells' => [['date' => '2024-02-29', 'quantity' => '8.0000']]]],
        ];
        $validator = Validator::make($valid, $request->rules());
        $request->replace($valid);
        $request->withValidator($validator);
        self::assertTrue($validator->passes(), json_encode($validator->errors()->all()));

        $bad = $valid;
        $bad['lines'][0]['cells'][] = ['date' => '2024-02-29', 'quantity' => '1.0000'];
        $bad['lines'][0]['unit_cost'] = '1e3';
        $bad['total'] = '999.00';
        $validator = Validator::make($bad, $request->rules());
        $request->replace($bad);
        $request->withValidator($validator);
        self::assertFalse($validator->passes());
        self::assertTrue($validator->errors()->has('lines.0.cells'));
        self::assertTrue($validator->errors()->has('lines.0.unit_cost'));
        self::assertTrue($validator->errors()->has('total'));
    }
}
