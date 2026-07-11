<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $fillable = ['chapter_id', 'name', 'description', 'credit_balance'];

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
}
