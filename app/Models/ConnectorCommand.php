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
            // Encrypted while the command is in flight; purged once read (ADR-04).
            'payload_json' => 'encrypted:array',
            'result_json' => 'encrypted:array',
            'deadline_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * ADR-04: once the caller has read what it needs, the operational data
     * sent to and returned by the connector must not stay in the control
     * plane. Envelope metadata (ids, op, status, error code, timings) stays
     * for auditing. Source configuration commands keep their data because the
     * connection screen reads it later; it holds no operational rows.
     */
    public function purgeOperationalData(): void
    {
        if (self::retainsData((string) $this->op) || ! $this->exists) {
            return;
        }

        $this->forceFill([
            'payload_json' => [],
            'result_json' => null,
        ])->save();
    }

    public static function retainsData(string $op): bool
    {
        return str_starts_with($op, 'source_config.');
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
