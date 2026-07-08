<?php

namespace App\Models;

use App\Enums\PlanPurpose;
use App\Enums\PlanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DrillingPlan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'mine',
        'level',
        'sector',
        'purpose',
        'planned_meters',
        'executed_meters',
        'status',
        'description',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => PlanPurpose::class,
            'status' => PlanStatus::class,
            'planned_meters' => 'decimal:2',
            'executed_meters' => 'decimal:2',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function platforms(): HasMany
    {
        return $this->hasMany(DrillingPlatform::class);
    }

    public function drillHoles(): HasMany
    {
        return $this->hasMany(DrillHole::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(PlanFile::class);
    }

    public function machineAvailability(): HasMany
    {
        return $this->hasMany(MachineAvailability::class);
    }
}
