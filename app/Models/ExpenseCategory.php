<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    protected $fillable = [
        'name',
        'description',
        'fund_type',
        'applies_service_fee',
        'applies_contingency',
        'service_fee_percentage',
    ];

    protected function casts(): array
    {
        return [
            'applies_service_fee' => 'boolean',
            'applies_contingency' => 'boolean',
            'service_fee_percentage' => 'decimal:2',
        ];
    }

    public function estimatedExpenses()
    {
        return $this->hasMany(EstimatedExpense::class);
    }

    public function actualExpenses()
    {
        return $this->hasMany(ActualExpense::class);
    }
}
