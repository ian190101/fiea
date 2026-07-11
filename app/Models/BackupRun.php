<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupRun extends Model
{
    protected $fillable = [
        'type',
        'status',
        'disk',
        'storage_file_id',
        'size_bytes',
        'checksum',
        'error_message',
        'created_by_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function storageFile()
    {
        return $this->belongsTo(StorageFile::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
