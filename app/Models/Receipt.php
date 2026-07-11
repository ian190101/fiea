<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Receipt extends Model
{
    protected $fillable = ['actual_expense_id', 'storage_file_id', 'receipt_number', 'issued_on', 'amount'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'amount' => 'decimal:2'];
    }

    public function actualExpense()
    {
        return $this->belongsTo(ActualExpense::class);
    }

    public function storageFile()
    {
        return $this->belongsTo(StorageFile::class);
    }
}
