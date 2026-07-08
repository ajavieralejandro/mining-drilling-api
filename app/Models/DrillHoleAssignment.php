<?php

namespace App\Models;

use App\Enums\RoleInHole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrillHoleAssignment extends Model
{
    protected $fillable = [
        'drill_hole_id',
        'user_id',
        'role_in_hole',
        'assigned_from',
        'assigned_to',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'role_in_hole' => RoleInHole::class,
            'assigned_from' => 'datetime',
            'assigned_to' => 'datetime',
            'active' => 'boolean',
        ];
    }

    public function drillHole(): BelongsTo
    {
        return $this->belongsTo(DrillHole::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
