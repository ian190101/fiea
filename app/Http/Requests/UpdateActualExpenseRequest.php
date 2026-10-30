<?php
declare(strict_types=1);
namespace App\Http\Requests;

final class UpdateActualExpenseRequest extends StoreActualExpenseRequest
{
    public function authorize(): bool
    {
        $expense=$this->route('actualExpense');
        return $expense && (int)$expense->trip_phase_id===(int)$this->input('trip_phase_id')
            && parent::authorize();
    }
}
