<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class PaymentExpectation extends Model
{
    protected $fillable = ['billing_case_id','leg','stage','expected_amount','basis','basis_invoice_id','source','reason','created_by_id'];
    protected function casts(): array { return ['expected_amount'=>'decimal:2','source'=>'array','version'=>'integer']; }
    public function billingCase(): BelongsTo { return $this->belongsTo(BillingCase::class); }
    public function basisInvoice(): BelongsTo { return $this->belongsTo(Invoice::class,'basis_invoice_id'); }
}
