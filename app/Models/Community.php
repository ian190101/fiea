<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Community extends Model
{
    protected $fillable = ['country_id', 'name', 'description'];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }
}
