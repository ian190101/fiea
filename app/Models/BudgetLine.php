<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BudgetLine extends Model
{
    protected $fillable = [
        'billing_case_id', 'catalog_key', 'description_en', 'description_es',
        'unit', 'section', 'bucket', 'arranged_eligible', 'sort_order',
        'legacy_estimated_expense_id', 'legacy_actual_expense_id',
    ];

    protected function casts(): array
    {
        return ['arranged_eligible' => 'boolean', 'sort_order' => 'integer'];
    }

    public function billingCase(): BelongsTo
    {
        return $this->belongsTo(BillingCase::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BudgetLineVersion::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }
}
