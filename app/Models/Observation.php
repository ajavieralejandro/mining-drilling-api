<?php

namespace App\Models;

use App\Enums\ObservationType;
use App\Enums\RiskLevel;
use App\Enums\RiskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Observation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'drill_hole_id',
        'user_id',
        'type',
        'body',
        'risk_level',
        'risk_status',
        'depth_detected',
        'critical_distance',
        'recommended_action',
        'closed_at',
        'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => ObservationType::class,
            'risk_level' => RiskLevel::class,
            'risk_status' => RiskStatus::class,
            'depth_detected' => 'decimal:2',
            'critical_distance' => 'decimal:2',
            'closed_at' => 'datetime',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isRisk(): bool
    {
        return $this->type === ObservationType::Risk;
    }
}
