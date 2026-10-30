<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BudgetCell extends Model
{
    protected $fillable = ['budget_line_version_id', 'spent_on', 'quantity', 'receipt_number'];

    protected function casts(): array
    {
        return ['spent_on' => 'date', 'quantity' => 'decimal:4'];
    }

    public function version(): BelongsTo { return $this->belongsTo(BudgetLineVersion::class, 'budget_line_version_id'); }
}
