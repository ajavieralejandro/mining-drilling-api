<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantMineHole extends Model
{
    use HasUlids;

    protected $table = 'tenant_mine_holes';

    protected $fillable = [
        'id',
        'tenant_id',
        'mine_id',
        'origin_id',
        'hole_id',
        'hole_code',
        'drill_hole_id',
        'status',
        'notes',
        'assigned_by',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function mine(): BelongsTo
    {
        return $this->belongsTo(TenantMine::class, 'mine_id');
    }

    public function drillHole(): BelongsTo
    {
        return $this->belongsTo(DrillHole::class, 'drill_hole_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
