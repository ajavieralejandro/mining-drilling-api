<?php

namespace App\Repositories\Gateway;

use App\Domain\Repositories\ObservationStore;
use App\Services\Connector\CommandDispatcher;
use App\Support\GatewayException;
use App\Support\TenantContext;

/**
 * Observations, observation types and hole geometry through the tenant's
 * connector. Every call dispatches one operation, reads the result and
 * purges the command (ADR-04).
 */
class GatewayObservationStore implements ObservationStore
{
    use RunsConnectorRecords;

    public function __construct(private readonly CommandDispatcher $dispatcher) {}

    public function types(TenantContext $context, bool $includeInactive, string $correlationId): array
    {
        $result = $this->run($context, 'observation_types.list@1', array_filter([
            'include_inactive' => $includeInactive,
        ]), $correlationId);

        return self::rows($result['items'] ?? null, $correlationId);
    }

    public function saveType(TenantContext $context, ?string $id, array $fields, string $correlationId): ?array
    {
        $payload = ['fields' => (object) $fields, 'user_id' => (string) $context->userId];
        if ($id !== null) {
            $payload['id'] = $id;
        }

        try {
            $result = $this->write($context, 'observation_types.upsert@1', $payload, $correlationId);
        } catch (GatewayException $e) {
            if ($e->errorCode === 'NOT_FOUND') {
                return null;
            }
            throw $e;
        }

        return $this->item($result, $correlationId);
    }

    public function observations(TenantContext $context, string $holeId, ?string $typeId, int $page, int $perPage, string $correlationId): array
    {
        $result = $this->run($context, 'observations.list@1', array_filter([
            'hole_id' => $holeId,
            'type_id' => $typeId,
            'page' => $page,
            'per_page' => $perPage,
        ], fn ($v) => $v !== null && $v !== ''), $correlationId);

        $items = self::rows($result['items'] ?? null, $correlationId);

        return ['items' => $items, 'total' => (int) ($result['total'] ?? count($items))];
    }

    public function record(TenantContext $context, string $holeId, string $typeId, string $body, ?string $depth, string $idempotencyKey, string $correlationId): array
    {
        $result = $this->write($context, 'observations.create@1', array_filter([
            'hole_id' => $holeId,
            'type_id' => $typeId,
            'body' => $body,
            'depth' => $depth,
            'user_id' => (string) $context->userId,
            'idempotency_key' => $idempotencyKey,
        ], fn ($v) => $v !== null && $v !== ''), $correlationId);

        return [
            'item' => $this->item($result, $correlationId),
            'replayed' => ($result['outcome'] ?? null) === 'replayed',
        ];
    }

    public function geometry(TenantContext $context, string $holeId, string $correlationId): array
    {
        $result = $this->run($context, 'hole_geometry.get@1', ['hole_id' => $holeId], $correlationId);

        $values = is_array($result['values'] ?? null) ? $result['values'] : [];
        $missing = is_array($result['missing'] ?? null) ? array_values($result['missing']) : [];

        return [
            'values' => array_map(fn ($v) => $v === null ? null : (string) $v, $values),
            'missing' => array_map('strval', $missing),
            'current_depth' => isset($result['current_depth']) ? (string) $result['current_depth'] : null,
            'marks' => self::rows($result['marks'] ?? [], $correlationId),
            'risks' => self::rows($result['risks'] ?? [], $correlationId),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function item(array $result, string $correlationId): array
    {
        if (! is_array($result['item'] ?? null) || ! isset($result['item']['id'])) {
            throw new GatewayException('INVALID_RESULT', 'Connector returned an invalid result', 502, null, $correlationId);
        }

        return $result['item'];
    }
}
