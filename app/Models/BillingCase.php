<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BillingCase extends Model
{
    protected $fillable = [
        'trip_phase_id', 'invoice_type', 'billing_mode', 'lifecycle_status',
        'bank_fee_initial', 'bank_fee_final', 'final_contingency', 'metadata',
        'closed_at', 'void_reason', 'treasury_note',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'bank_fee_initial' => 'decimal:2',
            'bank_fee_final' => 'decimal:2',
            'final_contingency' => 'decimal:2',
            'metadata' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    public function tripPhase(): BelongsTo { return $this->belongsTo(TripPhase::class); }
    public function lines(): HasMany { return $this->hasMany(BudgetLine::class); }
    public function codes(): HasMany { return $this->hasMany(BillingCaseCode::class); }
    public function currentInitial(): BelongsTo { return $this->belongsTo(Invoice::class, 'current_initial_id'); }
    public function currentFinal(): BelongsTo { return $this->belongsTo(Invoice::class, 'current_final_id'); }
    public function currentCode(): BelongsTo { return $this->belongsTo(BillingCaseCode::class, 'current_code_id'); }
    public function payments(): HasMany { return $this->hasMany(TreasuryPayment::class); }
    public function expectations(): HasMany { return $this->hasMany(PaymentExpectation::class); }
    public function creditEntries(): HasMany { return $this->hasMany(CreditEntry::class); }
}
