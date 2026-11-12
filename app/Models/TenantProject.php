<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantProject extends Model
{
    use HasUlids, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ARCHIVED = 'archived';

    public const VALID_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_COMPLETED,
        self::STATUS_ARCHIVED,
    ];

    protected $table = 'tenant_projects';

    protected $fillable = [
        'id',
        'tenant_id',
        'code',
        'name',
        'status',
        'description',
        'created_by',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function mines(): HasMany
    {
        return $this->hasMany(TenantMine::class, 'project_id');
    }

    public function mineHoles(): HasManyThrough
    {
        return $this->hasManyThrough(
            TenantMineHole::class,
            TenantMine::class,
            'project_id',
            'mine_id',
            'id',
            'id',
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
