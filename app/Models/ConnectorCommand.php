<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorCommand extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_TIMEOUT = 'timeout';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'request_id',
        'correlation_id',
        'tenant_id',
        'connector_id',
        'op',
        'payload_json',
        'status',
        'result_json',
        'error_code',
        'deadline_at',
        'dispatched_at',
        'completed_at',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'result_json' => 'array',
            'deadline_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
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
