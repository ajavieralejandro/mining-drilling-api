<?php

namespace App\Models;

use App\Enums\MachineStatus;
use App\Enums\MachineType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Machine extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'type',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => MachineType::class,
            'status' => MachineStatus::class,
        ];
    }

    public function drillHoles(): BelongsToMany
    {
        return $this->belongsToMany(DrillHole::class, 'drill_hole_machines')
            ->withPivot(['assigned_from', 'assigned_to', 'active'])
            ->withTimestamps();
    }

    public function availability(): HasMany
    {
        return $this->hasMany(MachineAvailability::class);
    }
}
