<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactPerson extends Model
{
    protected $fillable = ['full_name', 'email', 'phone', 'physical_address'];

    public function assignments()
    {
        return $this->hasMany(ContactAssignment::class);
    }
}
