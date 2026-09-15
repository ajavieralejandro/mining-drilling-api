<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The user <-> tenant link. A user's login identity is UndSurf's (see
 * User); the membership is what grants that identity an operational role
 * inside one tenant's data. tenant_id is NEVER trusted from client input —
 * it is only ever read off an active Membership resolved from the
 * authenticated user. See docs/architecture/data-ownership.md ("Caso User").
 *
 * A membership row is never deleted when access ends — closing it
 * (status=inactive, ended_at, deactivated_by, deactivation_reason) is the
 * only supported way to revoke it, and is what keeps the person's history
 * with that tenant. At most one row per user may be `active` at a time,
 * enforced by a DB partial unique index (see the 2026-09-12 migration) and
 * backstopped in application code — see User::activeMembership() and
 * MembershipLifecycleService, which is the only class allowed to change
 * `status`. See docs/audits/2026-09-12-undsurf-auditoria-ciclo-vida-usuarios.md.
 */
class Membership extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'id',
        'user_id',
        'tenant_id',
        'role',
        'status',
        'corporate_email',
        'external_hr_id',
        'started_at',
        'ended_at',
        'deactivated_by',
        'deactivation_reason',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
