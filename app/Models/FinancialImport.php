<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
final class FinancialImport extends Model
{
    protected $fillable = ['sha256','sheet','mapping_version','actor_id','status','source_file_id','expires_at','applied_at'];
    protected function casts(): array { return ['expires_at'=>'datetime','applied_at'=>'datetime']; }
    public function actor(): BelongsTo { return $this->belongsTo(User::class,'actor_id'); }
    public function sourceFile(): BelongsTo { return $this->belongsTo(StorageFile::class,'source_file_id'); }
    public function rows(): HasMany { return $this->hasMany(FinancialImportRow::class); }
}
