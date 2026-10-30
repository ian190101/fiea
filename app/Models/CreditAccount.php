<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
final class CreditAccount extends Model
{
    protected $fillable = ['team_id','currency'];
    protected function casts(): array { return ['balance'=>'decimal:2']; }
    public function team(): BelongsTo { return $this->belongsTo(Team::class); }
    public function entries(): HasMany { return $this->hasMany(CreditEntry::class); }
}
