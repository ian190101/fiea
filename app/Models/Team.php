<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $fillable = ['chapter_id', 'name', 'description'];

    protected function casts(): array
    {
        return ['credit_balance' => 'decimal:2'];
    }

    public function chapter()
    {
        return $this->belongsTo(Chapter::class);
    }

    public function contactAssignments()
    {
        return $this->hasMany(ContactAssignment::class);
    }

    public function tripPhases()
    {
        return $this->hasMany(TripPhase::class);
    }

    public function creditAccount()
    {
        return $this->hasOne(CreditAccount::class)->where('currency', 'USD');
    }
}
