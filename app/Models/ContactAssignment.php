<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactAssignment extends Model
{
    protected $fillable = [
        'contact_person_id',
        'chapter_id',
        'team_id',
        'role',
        'is_billing_contact',
        'is_email_recipient',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_billing_contact' => 'boolean',
            'is_email_recipient' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function contactPerson()
    {
        return $this->belongsTo(ContactPerson::class);
    }

    public function chapter()
    {
        return $this->belongsTo(Chapter::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
