<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TripPhase extends Model
{
    protected $fillable = [
        'project_id',
        'team_id',
        'assigned_technician_id',
        'phase',
        'starts_on',
        'ends_on',
        'volunteer_count',
        'staff_count',
        'status',
        'draft_pdf_file_id',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'version' => 'integer'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function assignedTechnician()
    {
        return $this->belongsTo(User::class, 'assigned_technician_id');
    }

    public function estimatedExpenses()
    {
        return $this->hasMany(EstimatedExpense::class);
    }

    public function actualExpenses()
    {
        return $this->hasMany(ActualExpense::class);
    }

    public function billingCases(): HasMany
    {
        return $this->hasMany(BillingCase::class);
    }

    public function draftPdfFile()
    {
        return $this->belongsTo(StorageFile::class, 'draft_pdf_file_id');
    }
}
