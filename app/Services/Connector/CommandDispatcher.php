<?php

namespace App\Services\Connector;

use App\Models\Connector;
use App\Models\ConnectorCommand;
use Illuminate\Support\Str;
use RuntimeException;

class CommandDispatcher
{
    public function __construct(
        private readonly PlatformAuditor $auditor,
        private readonly ConnectorPresence $presence,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(
        Connector $connector,
        string $op,
        array $payload,
        string $actorType = 'demo',
        ?string $actorId = null,
        ?int $timeoutSeconds = null,
        ?string $correlationId = null,
    ): ConnectorCommand {
        if (! in_array($op, config('connector.supported_ops', []), true)) {
            throw new RuntimeException('UNSUPPORTED_OP');
        }

        if (! $this->presence->assertOnline($connector)) {
            throw new RuntimeException('CONNECTOR_OFFLINE');
        }

        $timeout = $timeoutSeconds ?? (int) config('connector.demo_command_timeout_seconds', 10);
        $requestId = 'req_'.(string) Str::ulid();
        $correlationId ??= 'cor_'.(string) Str::ulid();

        $command = ConnectorCommand::create([
            'id' => (string) Str::ulid(),
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'tenant_id' => $connector->tenant_id,
            'connector_id' => $connector->id,
            'op' => $op,
            'payload_json' => $payload,
            'status' => ConnectorCommand::STATUS_PENDING,
            'deadline_at' => now()->addSeconds($timeout),
            'dispatched_at' => now(),
        ]);

        $this->auditor->log(
            event: 'dispatched',
            requestId: $requestId,
            correlationId: $correlationId,
            actorType: $actorType,
            actorId: $actorId,
            tenantId: $connector->tenant_id,
            connectorId: $connector->id,
            op: $op,
            resultSummary: ['status' => 'pending'],
        );

        return $command;
    }

    public function waitForResult(ConnectorCommand $command, ?int $timeoutSeconds = null): ConnectorCommand
    {
        $timeout = $timeoutSeconds ?? (int) config('connector.demo_command_timeout_seconds', 10);
        $intervalMs = (int) config('connector.demo_poll_interval_ms', 200);
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $command->refresh();

            if (in_array($command->status, [
                ConnectorCommand::STATUS_COMPLETED,
                ConnectorCommand::STATUS_FAILED,
            ], true)) {
                return $command;
            }

            if ($command->deadline_at->isPast()) {
                break;
            }

            usleep($intervalMs * 1000);
        }

        $command->refresh();

        if (! in_array($command->status, [
            ConnectorCommand::STATUS_COMPLETED,
            ConnectorCommand::STATUS_FAILED,
        ], true)) {
            $command->forceFill([
                'status' => ConnectorCommand::STATUS_TIMEOUT,
                'error_code' => 'TIMEOUT',
                'completed_at' => now(),
                'duration_ms' => (int) max(0, now()->diffInMilliseconds($command->dispatched_at)),
            ])->save();

            $this->auditor->log(
                event: 'timeout',
                requestId: $command->request_id,
                correlationId: $command->correlation_id,
                actorType: 'system',
                tenantId: $command->tenant_id,
                connectorId: $command->connector_id,
                op: $command->op,
                resultSummary: ['status' => 'timeout', 'error_code' => 'TIMEOUT'],
                durationMs: $command->duration_ms,
            );
        }

        return $command->fresh();
    }
}
