<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
final class FinancialImportRow extends Model
{
    protected $fillable = ['financial_import_id','section','source_row','raw','normalized','errors','resolutions','target_case_id','expected_version','applied_at'];
    protected function casts(): array { return ['raw'=>'array','normalized'=>'array','errors'=>'array','resolutions'=>'array','expected_version'=>'integer','applied_at'=>'datetime']; }
    public function financialImport(): BelongsTo { return $this->belongsTo(FinancialImport::class); }
    public function targetCase(): BelongsTo { return $this->belongsTo(BillingCase::class,'target_case_id'); }
    public function cases(): BelongsToMany { return $this->belongsToMany(BillingCase::class,'financial_import_row_cases')->withPivot('expected_version'); }
}
