<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class TreasuryPayment extends Model
{
    protected $fillable = ['billing_case_id','invoice_id','leg','stage','direction','amount','paid_on','method','reference','note','idempotency_key','payload_hash','source_key','reverses_payment_id','created_by_id'];
    protected function casts(): array { return ['amount'=>'decimal:2','paid_on'=>'date']; }
    public function billingCase(): BelongsTo { return $this->belongsTo(BillingCase::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function reversesPayment(): BelongsTo { return $this->belongsTo(self::class,'reverses_payment_id'); }
}
