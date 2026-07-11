<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = [
        'logo_file_id',
        'primary_color',
        'secondary_color',
        'accent_color',
        'lock_final_invoice_by_default',
        'accounting_can_edit_summary',
        'updated_by_id',
    ];

    protected function casts(): array
    {
        return [
            'lock_final_invoice_by_default' => 'boolean',
            'accounting_can_edit_summary' => 'boolean',
        ];
    }

    public function logoFile()
    {
        return $this->belongsTo(StorageFile::class, 'logo_file_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}
