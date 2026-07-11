<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActualExpense extends Model
{
    protected $fillable = [
        'trip_phase_id',
        'estimated_expense_id',
        'expense_category_id',
        'reported_by_id',
        'description',
        'unit',
        'final_unit_cost',
        'final_quantity',
        'real_total',
        'receipt_number',
        'fund_type',
        'reported_at',
    ];

    protected function casts(): array
    {
        return [
            'final_unit_cost' => 'decimal:2',
            'final_quantity' => 'decimal:2',
            'real_total' => 'decimal:2',
            'reported_at' => 'datetime',
        ];
    }

    public function tripPhase()
    {
        return $this->belongsTo(TripPhase::class);
    }

    public function estimatedExpense()
    {
        return $this->belongsTo(EstimatedExpense::class);
    }

    public function expenseCategory()
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    public function reportedBy()
    {
        return $this->belongsTo(User::class, 'reported_by_id');
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class);
    }
}
