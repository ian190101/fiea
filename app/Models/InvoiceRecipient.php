<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceRecipient extends Model
{
    protected $fillable = ['invoice_id', 'contact_person_id', 'email', 'recipient_type'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function contactPerson()
    {
        return $this->belongsTo(ContactPerson::class);
    }
}
