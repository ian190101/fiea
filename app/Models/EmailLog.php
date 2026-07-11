<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    protected $fillable = [
        'invoice_id',
        'subject',
        'body',
        'status',
        'source',
        'retry_count',
        'last_attempted_at',
        'next_retry_at',
        'error_message',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'retry_count' => 'integer',
            'last_attempted_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
