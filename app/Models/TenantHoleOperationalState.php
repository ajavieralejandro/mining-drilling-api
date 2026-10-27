<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantHoleOperationalState extends Model
{
    use HasUlids;

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ABORTED = 'aborted';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'tenant_hole_operational_states';

    protected $fillable = [
        'id',
        'tenant_id',
        'origin_id',
        'hole_id',
        'status',
        'initial_depth',
        'current_depth',
        'depth_initialized',
        'depth_initialized_at',
        'depth_initialized_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'initial_depth' => 'decimal:2',
            'current_depth' => 'decimal:2',
            'depth_initialized' => 'boolean',
            'depth_initialized_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TenantHoleAssignment::class, 'operational_state_id');
    }

    public function activeAssignments(): HasMany
    {
        return $this->assignments()->where('active', true);
    }

    public function progressLogs(): HasMany
    {
        return $this->hasMany(TenantHoleProgressLog::class, 'operational_state_id');
    }

    public function initializedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'depth_initialized_by');
    }

    public function isOperable(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function scopeForWell(Builder $query, string $tenantId, string $originId, string $holeId): Builder
    {
        return $query->where('tenant_id', $tenantId)
            ->where('origin_id', $originId)
            ->where('hole_id', $holeId);
    }
}
