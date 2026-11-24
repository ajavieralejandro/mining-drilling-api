<?php

namespace App\Repositories\Gateway;

use App\Domain\Repositories\HoleRegistry;
use App\Services\Connector\CommandDispatcher;
use App\Support\TenantContext;

/**
 * Holes registered from UndSurf, through the tenant's connector. Every call
 * dispatches one operation, reads the result and purges the command (ADR-04).
 */
class GatewayHoleRegistry implements HoleRegistry
{
    use RunsConnectorRecords;

    public function __construct(private readonly CommandDispatcher $dispatcher) {}

    public function find(TenantContext $context, string $id, string $correlationId): ?array
    {
        return $this->nullWhenMissing(fn () => $this->itemOf($this->run($context, 'holes.get@1', ['id' => $id], $correlationId), $correlationId));
    }

    public function save(TenantContext $context, ?string $id, array $fields, string $correlationId): ?array
    {
        $payload = ['fields' => (object) $fields, 'user_id' => (string) $context->userId];
        if ($id !== null) {
            $payload['id'] = $id;
        }

        return $this->nullWhenMissing(fn () => $this->itemOf($this->write($context, 'holes.upsert@1', $payload, $correlationId), $correlationId));
    }
}
