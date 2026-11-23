<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantCoreLog extends Model
{
    use HasUlids;

    protected $table = 'tenant_core_logs';

    protected $fillable = [
        'id',
        'tenant_id',
        'origin_id',
        'hole_id',
        'box_number',
        'run_from',
        'run_to',
        'drill_length',
        'recovered_meters',
        'rqd_meters',
        'rqd_percentage',
        'core_loss_meters',
        'logged_by',
        'logged_at',
        'geology_notes',
        'photo_url',
    ];

    protected function casts(): array
    {
        return [
            'run_from' => 'decimal:2',
            'run_to' => 'decimal:2',
            'drill_length' => 'decimal:2',
            'recovered_meters' => 'decimal:2',
            'rqd_meters' => 'decimal:2',
            'rqd_percentage' => 'decimal:2',
            'core_loss_meters' => 'decimal:2',
            'logged_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function loggedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }

    public function scopeForHole(Builder $query, string $tenantId, string $holeId): Builder
    {
        return $query->where('tenant_id', $tenantId)->where('hole_id', $holeId);
    }

    public function scopeForWell(Builder $query, string $tenantId, string $originId, string $holeId): Builder
    {
        return $query->where('tenant_id', $tenantId)
            ->where('origin_id', $originId)
            ->where('hole_id', $holeId);
    }
}
