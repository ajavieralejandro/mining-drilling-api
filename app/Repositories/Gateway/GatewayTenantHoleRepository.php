<?php

namespace App\Repositories\Gateway;

use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Services\Connector\CommandDispatcher;
use App\Support\TenantContext;

class GatewayTenantHoleRepository implements TenantHoleRepositoryInterface
{
    use GatewayCommandTrait;

    public function __construct(private readonly CommandDispatcher $dispatcher) {}

    public function list(TenantContext $context, string $correlationId, int $limit = 10): array
    {
        $connector = $this->resolveConnector($context->tenantId, $correlationId);

        $command = $this->dispatchAndWait(
            $this->dispatcher,
            $connector,
            'drill_holes.list@1',
            ['limit' => $limit],
            $context->userId,
            $correlationId,
        );

        $this->assertOk($command);

        return [
            'items' => $command->result_json['items'] ?? [],
            'request_id' => $command->request_id,
            'correlation_id' => $command->correlation_id,
        ];
    }

    public function find(TenantContext $context, string $correlationId, string $holeId): array
    {
        $connector = $this->resolveConnector($context->tenantId, $correlationId);

        $command = $this->dispatchAndWait(
            $this->dispatcher,
            $connector,
            'drill_holes.get@1',
            ['id' => $holeId],
            $context->userId,
            $correlationId,
        );

        $this->assertOk($command);

        return [
            'item' => $command->result_json['item'] ?? [],
            'request_id' => $command->request_id,
            'correlation_id' => $command->correlation_id,
        ];
    }
}
