<?php

namespace App\Models;

use App\Enums\PlatformStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DrillingPlatform extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'drilling_plan_id',
        'code',
        'name',
        'easting',
        'northing',
        'elevation',
        'gallery',
        'level',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => PlatformStatus::class,
            'easting' => 'decimal:4',
            'northing' => 'decimal:4',
            'elevation' => 'decimal:4',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(DrillingPlan::class, 'drilling_plan_id');
    }

    public function drillHoles(): HasMany
    {
        return $this->hasMany(DrillHole::class);
    }

    public function machineAvailability(): HasMany
    {
        return $this->hasMany(MachineAvailability::class);
    }
}
