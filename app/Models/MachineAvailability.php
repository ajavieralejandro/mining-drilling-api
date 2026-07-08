<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineAvailability extends Model
{
    protected $table = 'machine_availability';

    protected $fillable = [
        'machine_id',
        'drilling_plan_id',
        'drilling_platform_id',
        'available_from',
        'available_to',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'available_from' => 'datetime',
            'available_to' => 'datetime',
            'active' => 'boolean',
        ];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(DrillingPlan::class, 'drilling_plan_id');
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(DrillingPlatform::class, 'drilling_platform_id');
    }
}
