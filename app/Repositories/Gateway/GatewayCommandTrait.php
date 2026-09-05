<?php

namespace App\Repositories\Gateway;

use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Services\Connector\CommandDispatcher;
use App\Support\GatewayException;
use RuntimeException;

/**
 * Shared connector-resolution and result-handling for the tenant-scoped
 * Gateway repositories. Reuses CommandDispatcher exactly as the existing
 * demo endpoint does (App\Http\Controllers\Api\Internal\DemoListHolesController)
 * — no second dispatch mechanism was introduced.
 */
trait GatewayCommandTrait
{
    private function resolveConnector(string $tenantId, ?string $correlationId = null): Connector
    {
        $connector = Connector::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('last_seen_at')
            ->first();

        if ($connector === null) {
            throw new GatewayException('CONNECTOR_OFFLINE', 'No connector registered for this tenant', 503, null, $correlationId);
        }

        return $connector;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchAndWait(
        CommandDispatcher $dispatcher,
        Connector $connector,
        string $op,
        array $payload,
        int $userId,
        ?string $correlationId = null,
    ): ConnectorCommand {
        try {
            $command = $dispatcher->dispatch(
                connector: $connector,
                op: $op,
                payload: $payload,
                actorType: 'user',
                actorId: (string) $userId,
                correlationId: $correlationId,
            );
        } catch (RuntimeException $e) {
            $code = $e->getMessage();

            if ($code === 'CONNECTOR_OFFLINE') {
                throw new GatewayException('CONNECTOR_OFFLINE', 'Gateway dispatch failed', 503, null, $correlationId);
            }

            if ($code === 'UNSUPPORTED_OP') {
                throw new GatewayException('UNSUPPORTED_OP', 'Gateway dispatch failed', 422, null, $correlationId);
            }

            report($e);

            throw new GatewayException('INTERNAL_ERROR', 'Internal server error.', 500, null, $correlationId);
        }

        return $dispatcher->waitForResult($command);
    }

    private function assertOk(ConnectorCommand $command): void
    {
        if ($command->status === ConnectorCommand::STATUS_TIMEOUT) {
            throw new GatewayException(
                'TIMEOUT',
                'Connector did not respond before deadline',
                504,
                $command->request_id,
                $command->correlation_id,
            );
        }

        if ($command->status === ConnectorCommand::STATUS_FAILED) {
            $code = $command->error_code ?: 'ADAPTER_ERROR';
            $httpStatus = match ($code) {
                'NOT_FOUND' => 404,
                'INVALID_PAYLOAD' => 422,
                default => 502,
            };

            throw new GatewayException(
                $code,
                'Connector reported an error',
                $httpStatus,
                $command->request_id,
                $command->correlation_id,
            );
        }
    }
}
