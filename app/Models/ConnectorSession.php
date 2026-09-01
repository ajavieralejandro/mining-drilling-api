<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorSession extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'connector_id',
        'status',
        'started_at',
        'last_heartbeat_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }
}
