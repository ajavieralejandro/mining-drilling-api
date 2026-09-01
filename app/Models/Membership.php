<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The user <-> tenant link. A user's login identity is UndSurf's (see
 * User); the membership is what grants that identity an operational role
 * inside one tenant's data. tenant_id is NEVER trusted from client input —
 * it is only ever read off an active Membership resolved from the
 * authenticated user. See docs/architecture/data-ownership.md ("Caso User").
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
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
