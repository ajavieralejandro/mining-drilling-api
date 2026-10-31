<?php

namespace App\Services\Source;

use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Services\Connector\CommandDispatcher;
use App\Support\GatewayException;
use RuntimeException;

class DispatchingSourceGateway implements SourceCommandGateway
{
    public function __construct(private readonly CommandDispatcher $dispatcher) {}

    public function execute(
        Connector $connector,
        string $operation,
        array $payload,
        int $userId,
        ?string $correlationId,
    ): ConnectorCommand {
        try {
            $command = $this->dispatcher->dispatch(
                connector: $connector,
                op: $operation,
                payload: $payload,
                actorType: 'user',
                actorId: (string) $userId,
                timeoutSeconds: (int) config('connector.source_command_timeout_seconds', 15),
                correlationId: $correlationId,
            );
        } catch (RuntimeException $e) {
            $code = $e->getMessage();
            if ($code === 'CONNECTOR_OFFLINE') {
                throw new GatewayException('CONNECTOR_OFFLINE', 'El Connector está desconectado. La configuración anterior sigue activa.', 503, null, $correlationId);
            }
            if ($code === 'UNSUPPORTED_OP') {
                throw new GatewayException('UNSUPPORTED_OP', 'Este Connector todavía no puede aplicar una configuración. La base en uso no cambió.', 422, null, $correlationId);
            }

            throw new GatewayException('INTERNAL_ERROR', 'Internal server error.', 500, null, $correlationId);
        }

        return $this->dispatcher->waitForResult($command, (int) config('connector.source_command_timeout_seconds', 15));
    }
}
