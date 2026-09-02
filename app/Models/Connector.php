<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Connector extends Model
{
    public const STATUS_PENDING_AUTH = 'pending_auth';

    public const STATUS_ONLINE = 'online';

    public const STATUS_OFFLINE = 'offline';

    public const STATUS_REVOKED = 'revoked';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'label',
        'status',
        'version',
        'capabilities',
        'connector_token_hash',
        'last_seen_at',
    ];

    protected $hidden = [
        'connector_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ConnectorSession::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(ConnectorCommand::class);
    }

    public function isOnline(?int $ttlSeconds = null): bool
    {
        if ($this->status === self::STATUS_REVOKED) {
            return false;
        }

        $ttl = $ttlSeconds ?? (int) config('connector.online_ttl_seconds', 60);

        if ($this->last_seen_at === null) {
            return false;
        }

        return $this->last_seen_at->greaterThan(now()->subSeconds($ttl))
            && $this->status === self::STATUS_ONLINE;
    }
}
