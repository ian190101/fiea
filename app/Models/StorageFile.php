<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageFile extends Model
{
    protected $fillable = [
        'provider',
        'bucket',
        'object_key',
        'original_name',
        'mime_type',
        'size_bytes',
        'checksum',
        'public_url',
        'uploaded_by_id',
    ];

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function diskName(): string
    {
        return $this->provider === 'cloudflare_r2' ? 'r2' : 'local';
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class);
    }
}
