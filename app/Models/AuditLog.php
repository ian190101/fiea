<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            $id = request()?->attributes->get('correlation_id');
            if ($log->correlation_id === null && is_string($id)) $log->correlation_id = $id;
        });
    }
    protected $fillable = [
        'user_id',
        'action',
        'module',
        'auditable_type',
        'auditable_id',
        'ip_address',
        'user_agent',
        'metadata',
        'trip_phase_id',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function auditable()
    {
        return $this->morphTo();
    }
}
