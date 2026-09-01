<?php

namespace App\Services\Connector;

use App\Models\PlatformAuditLog;
use Illuminate\Support\Str;

class PlatformAuditor
{
    public function log(
        string $event,
        ?string $requestId = null,
        ?string $correlationId = null,
        string $actorType = 'system',
        ?string $actorId = null,
        ?string $tenantId = null,
        ?string $connectorId = null,
        ?string $op = null,
        ?array $resultSummary = null,
        ?int $durationMs = null,
    ): PlatformAuditLog {
        return PlatformAuditLog::create([
            'id' => (string) Str::ulid(),
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'tenant_id' => $tenantId,
            'connector_id' => $connectorId,
            'op' => $op,
            'event' => $event,
            'result_summary' => $resultSummary,
            'duration_ms' => $durationMs,
            'created_at' => now(),
        ]);
    }
}
