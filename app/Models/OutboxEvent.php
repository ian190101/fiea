<?php
declare(strict_types=1);
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OutboxEvent extends Model
{
    protected $fillable = ['type', 'aggregate_id', 'dedup_key', 'payload', 'status',
        'available_at', 'locked_until', 'attempts', 'error_reference'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'available_at' => 'datetime',
            'locked_until' => 'datetime', 'attempts' => 'integer'];
    }
}
