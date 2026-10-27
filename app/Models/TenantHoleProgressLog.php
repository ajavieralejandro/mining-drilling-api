<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantHoleProgressLog extends Model
{
    use HasUlids;

    protected $table = 'tenant_hole_progress_logs';

    protected $fillable = [
        'id',
        'tenant_id',
        'operational_state_id',
        'origin_id',
        'hole_id',
        'user_id',
        'depth_from',
        'depth_to',
        'depth_current',
        'shift',
        'idempotency_key',
        'payload_hash',
        'crew_snapshot',
        'logged_at',
        'observations',
    ];

    protected function casts(): array
    {
        return [
            'depth_from' => 'decimal:2',
            'depth_to' => 'decimal:2',
            'depth_current' => 'decimal:2',
            'crew_snapshot' => 'array',
            'logged_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function operationalState(): BelongsTo
    {
        return $this->belongsTo(TenantHoleOperationalState::class, 'operational_state_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForWell(Builder $query, string $tenantId, string $originId, string $holeId): Builder
    {
        return $query->where('tenant_id', $tenantId)
            ->where('origin_id', $originId)
            ->where('hole_id', $holeId);
    }

    public function scopeForHole(Builder $query, string $tenantId, string $holeId): Builder
    {
        return $query->where('tenant_id', $tenantId)->where('hole_id', $holeId);
    }
}
