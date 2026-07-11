<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Chapter extends Model
{
    protected $fillable = ['chapter_type_id', 'university_id', 'name', 'description'];

    public function chapterType()
    {
        return $this->belongsTo(ChapterType::class);
    }

    public function university()
    {
        return $this->belongsTo(University::class);
    }

    public function teams()
    {
        return $this->hasMany(Team::class);
    }

    public function contactAssignments()
    {
        return $this->hasMany(ContactAssignment::class);
    }
}
