<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    protected $fillable = ['name', 'description'];

    public function communities()
    {
        return $this->hasMany(Community::class);
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }
}
