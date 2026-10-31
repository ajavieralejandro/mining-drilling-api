<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSourcePolicy extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'tenant_id';

    protected $keyType = 'string';

    protected $fillable = [
        'tenant_id',
        'allow_loopback',
        'allow_private',
        'allowed_hosts',
        'allowed_ports',
    ];

    protected function casts(): array
    {
        return [
            'allow_loopback' => 'boolean',
            'allow_private' => 'boolean',
            'allowed_hosts' => 'array',
            'allowed_ports' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Missing row means the default policy: private databases are allowed
     * and loopback is not. There is no request field that can weaken this.
     */
    public static function resolved(string $tenantId): self
    {
        $existing = static::query()->find($tenantId);

        if ($existing !== null) {
            return $existing;
        }

        return new static([
            'tenant_id' => $tenantId,
            'allow_loopback' => false,
            'allow_private' => true,
            'allowed_hosts' => [],
            'allowed_ports' => [],
        ]);
    }
}
