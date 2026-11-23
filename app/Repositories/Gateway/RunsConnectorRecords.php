<?php

namespace App\Repositories\Gateway;

use App\Models\ConnectorCommand;
use App\Support\GatewayException;
use App\Support\TenantContext;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Runs one connector operation over records that live in the client
 * database: dispatch, wait, map the outcome to the API contract and purge
 * the command (ADR-04). The using class holds a CommandDispatcher in
 * $this->dispatcher.
 */
trait RunsConnectorRecords
{
    use GatewayCommandTrait;

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $rows, string $correlationId): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new GatewayException('INVALID_RESULT', 'Connector returned an invalid result', 502, null, $correlationId);
        }

        return $rows;
    }

    /**
     * Runs a write and turns its outcome into the API contract: a broken
     * business rule is a 422 on the reported field, a reused idempotency key
     * with different content a 409.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function write(TenantContext $context, string $op, array $payload, string $correlationId): array
    {
        $result = $this->run($context, $op, $payload, $correlationId);

        $outcome = $result['outcome'] ?? null;
        if ($outcome === 'rejected') {
            $field = (string) ($result['violation']['field'] ?? 'payload');
            $message = (string) ($result['violation']['message'] ?? 'La operación fue rechazada por el origen.');

            throw ValidationException::withMessages([$field => $message]);
        }
        if ($outcome === 'conflict') {
            throw new HttpException(409, GatewayHoleRecordsStore::CONFLICT_MESSAGE);
        }
        if (! in_array($outcome, ['created', 'replayed', 'ok'], true)) {
            throw new GatewayException('INVALID_RESULT', 'Connector returned an invalid result', 502, null, $correlationId);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function run(TenantContext $context, string $op, array $payload, string $correlationId): array
    {
        $connector = $this->resolveConnector($context->tenantId, $correlationId);
        $command = $this->dispatchAndWait($this->dispatcher, $connector, $op, $payload, $context->userId, $correlationId);

        try {
            if ($command->status === ConnectorCommand::STATUS_FAILED && $command->error_code === 'UNSUPPORTED_OP') {
                throw new GatewayException(
                    'UNSUPPORTED_OP',
                    'El conector de esta empresa todavía no admite esta operación en su base. Hay que actualizarlo y crear las tablas undsurf_*.',
                    422,
                    $command->request_id,
                    $command->correlation_id,
                );
            }
            $this->assertOk($command);

            $result = is_array($command->result_json) ? $command->result_json : [];
        } finally {
            $command->purgeOperationalData();
        }

        return $result;
    }

    /**
     * Runs $call and turns the connector's NOT_FOUND into null.
     *
     * @template T
     *
     * @param  \Closure(): T  $call
     * @return T|null
     */
    private function nullWhenMissing(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (GatewayException $e) {
            if ($e->errorCode === 'NOT_FOUND') {
                return null;
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function itemOf(array $result, string $correlationId): array
    {
        if (! is_array($result['item'] ?? null) || ! isset($result['item']['id'])) {
            throw new GatewayException('INVALID_RESULT', 'Connector returned an invalid result', 502, null, $correlationId);
        }

        return $result['item'];
    }
}
