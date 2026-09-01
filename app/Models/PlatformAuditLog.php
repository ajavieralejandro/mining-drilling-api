<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformAuditLog extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'request_id',
        'correlation_id',
        'actor_type',
        'actor_id',
        'tenant_id',
        'connector_id',
        'op',
        'event',
        'result_summary',
        'duration_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'result_summary' => 'array',
            'created_at' => 'datetime',
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
}
