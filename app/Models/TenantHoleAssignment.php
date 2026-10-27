<?php

namespace App\Models;

use App\Enums\RoleInHole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantHoleAssignment extends Model
{
    use HasUlids;

    protected $table = 'tenant_hole_assignments';

    protected $fillable = [
        'id',
        'tenant_id',
        'operational_state_id',
        'origin_id',
        'hole_id',
        'user_id',
        'role_in_hole',
        'assigned_by',
        'assigned_from',
        'assigned_to',
        'active',
        'notes',
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

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
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
