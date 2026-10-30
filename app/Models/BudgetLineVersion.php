<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BudgetLineVersion extends Model
{
    protected $fillable = ['budget_line_id', 'stage', 'unit_cost', 'quantity', 'receipt_number', 'is_captured'];

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:4', 'quantity' => 'decimal:4', 'is_captured' => 'boolean'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    public function cells(): HasMany
    {
        return $this->hasMany(BudgetCell::class);
    }
}
