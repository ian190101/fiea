<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class AccountingReview extends Model
{
    protected $fillable = ['project_id','team_id','period_year','operating_total','accounting_total','difference','note','source','reviewed_by_id','reviewed_at'];
    protected function casts(): array { return ['period_year'=>'integer','operating_total'=>'decimal:2','accounting_total'=>'decimal:2','difference'=>'decimal:2','version'=>'integer','source'=>'array','reviewed_at'=>'datetime']; }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function team(): BelongsTo { return $this->belongsTo(Team::class); }
}
