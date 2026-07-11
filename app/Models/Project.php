<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $fillable = [
        'country_id',
        'community_id',
        'code',
        'name',
        'started_on',
        'closed_on',
        'description',
    ];

    protected function casts(): array
    {
        return ['started_on' => 'date', 'closed_on' => 'date'];
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function community()
    {
        return $this->belongsTo(Community::class);
    }

    public function tripPhases()
    {
        return $this->hasMany(TripPhase::class);
    }
}
