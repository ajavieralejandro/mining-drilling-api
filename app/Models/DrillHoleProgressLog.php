<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrillHoleProgressLog extends Model
{
    protected $fillable = [
        'drill_hole_id',
        'user_id',
        'depth_from',
        'depth_to',
        'depth_current',
        'shift',
        'logged_at',
        'observations',
    ];

    protected function casts(): array
    {
        return [
            'depth_from' => 'decimal:2',
            'depth_to' => 'decimal:2',
            'depth_current' => 'decimal:2',
            'logged_at' => 'datetime',
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
