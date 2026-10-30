<?php
declare(strict_types=1);
namespace App\Http\Requests;

final class UpdateEstimatedExpenseRequest extends StoreEstimatedExpenseRequest
{
    public function authorize(): bool
    {
        $expense=$this->route('estimatedExpense');
        return $expense && (int)$expense->trip_phase_id===(int)$this->input('trip_phase_id')
            && parent::authorize();
    }
}
