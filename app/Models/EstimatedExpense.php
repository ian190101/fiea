<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstimatedExpense extends Model
{
    protected $fillable = [
        'trip_phase_id',
        'expense_category_id',
        'description',
        'unit',
        'initial_unit_cost',
        'initial_quantity',
        'estimated_total',
        'fund_type',
    ];

    protected function casts(): array
    {
        return [
            'initial_unit_cost' => 'decimal:2',
            'initial_quantity' => 'decimal:2',
            'estimated_total' => 'decimal:2',
        ];
    }

    public function tripPhase()
    {
        return $this->belongsTo(TripPhase::class);
    }

    public function expenseCategory()
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    public function actualExpenses()
    {
        return $this->hasMany(ActualExpense::class);
    }
}
