<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BillingCaseCode extends Model
{
    protected $fillable = ['billing_case_id', 'code', 'year', 'source_note'];

    protected function casts(): array { return ['year' => 'integer']; }

    public function billingCase(): BelongsTo { return $this->belongsTo(BillingCase::class); }
}
