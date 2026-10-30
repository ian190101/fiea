<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class CreditEntry extends Model
{
    protected $fillable = ['credit_account_id','billing_case_id','invoice_id','kind','amount','idempotency_key','payload_hash','source_key','closure_key','reverses_entry_id','reason','created_by_id'];
    protected function casts(): array { return ['amount'=>'decimal:2']; }
    public function account(): BelongsTo { return $this->belongsTo(CreditAccount::class,'credit_account_id'); }
    public function billingCase(): BelongsTo { return $this->belongsTo(BillingCase::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
}
