<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrillHoleMachine extends Model
{
    protected $fillable = [
        'drill_hole_id',
        'machine_id',
        'assigned_from',
        'assigned_to',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'assigned_from' => 'datetime',
            'assigned_to' => 'datetime',
            'active' => 'boolean',
        ];
    }

    public function drillHole(): BelongsTo
    {
        return $this->belongsTo(DrillHole::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
