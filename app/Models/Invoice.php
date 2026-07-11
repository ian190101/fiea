<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'trip_phase_id',
        'contact_person_id',
        'pdf_file_id',
        'created_by_id',
        'approved_by_id',
        'code',
        'type',
        'stage',
        'status',
        'accounting_status',
        'total_dr',
        'total_wodr',
        'grand_total',
        'balance_conciliation',
        'accounting_note',
        'accounting_reviewed_by_id',
        'accounting_reviewed_at',
        'sent_at',
        'paid_at',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'total_dr' => 'decimal:2',
            'total_wodr' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'balance_conciliation' => 'decimal:2',
            'accounting_reviewed_at' => 'datetime',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function tripPhase()
    {
        return $this->belongsTo(TripPhase::class);
    }

    public function contactPerson()
    {
        return $this->belongsTo(ContactPerson::class);
    }

    public function pdfFile()
    {
        return $this->belongsTo(StorageFile::class, 'pdf_file_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function accountingReviewedBy()
    {
        return $this->belongsTo(User::class, 'accounting_reviewed_by_id');
    }

    public function recipients()
    {
        return $this->hasMany(InvoiceRecipient::class);
    }

    public function emailLogs()
    {
        return $this->hasMany(EmailLog::class);
    }
}
