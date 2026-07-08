<?php

namespace App\Models;

use App\Enums\HoleStatus;
use App\Enums\HoleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DrillHole extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'drilling_plan_id',
        'drilling_platform_id',
        'order_number',
        'rec_id',
        'hole_id',
        'target',
        'length',
        'current_depth',
        'azimuth',
        'dip',
        'hole_type',
        'status',
        'easting',
        'northing',
        'elevation',
        'coordinate_system',
        'observations',
    ];

    protected function casts(): array
    {
        return [
            'hole_type' => HoleType::class,
            'status' => HoleStatus::class,
            'length' => 'decimal:2',
            'current_depth' => 'decimal:2',
            'azimuth' => 'decimal:2',
            'dip' => 'decimal:2',
            'easting' => 'decimal:4',
            'northing' => 'decimal:4',
            'elevation' => 'decimal:4',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(DrillingPlan::class, 'drilling_plan_id');
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(DrillingPlatform::class, 'drilling_platform_id');
    }

    public function machines(): BelongsToMany
    {
        return $this->belongsToMany(Machine::class, 'drill_hole_machines')
            ->withPivot(['assigned_from', 'assigned_to', 'active'])
            ->withTimestamps();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DrillHoleAssignment::class);
    }

    public function progressLogs(): HasMany
    {
        return $this->hasMany(DrillHoleProgressLog::class);
    }

    public function holeObservations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    public function riskObservations(): HasMany
    {
        return $this->hasMany(Observation::class)->where('type', 'risk');
    }
}
