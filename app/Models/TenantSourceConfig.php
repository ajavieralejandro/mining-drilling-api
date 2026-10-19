<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSourceConfig extends Model
{
    public const STATE_DRAFT = 'draft';

    public const STATE_TESTED = 'tested';

    public const STATE_ACTIVE = 'active';

    public const STATE_SUPERSEDED = 'superseded';

    public const STATE_CANCELLED = 'cancelled';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'connector_id',
        'version',
        'state',
        'display_name',
        'engine',
        'host',
        'port',
        'database_name',
        'schema_name',
        'username',
        'password_ciphertext',
        'ca_ciphertext',
        'tls_mode',
        'installation_location',
        'fingerprint',
        'created_by',
        'tested_at',
        'test_error_code',
        'test_latency_ms',
        'test_tls_verified',
        'test_read_only',
        'applied_at',
    ];

    protected $hidden = [
        'password_ciphertext',
        'ca_ciphertext',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'version' => 'integer',
            'tested_at' => 'datetime',
            'applied_at' => 'datetime',
            'test_latency_ms' => 'integer',
            'test_tls_verified' => 'boolean',
            'test_read_only' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
