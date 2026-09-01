<?php

namespace App\Repositories\Gateway;

use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Services\Connector\CommandDispatcher;
use App\Support\TenantContext;

class GatewayTenantHoleRepository implements TenantHoleRepositoryInterface
{
    use GatewayCommandTrait;

    public function __construct(private readonly CommandDispatcher $dispatcher) {}

    public function list(TenantContext $context, int $limit = 10): array
    {
        $connector = $this->resolveConnector($context->tenantId);

        $command = $this->dispatchAndWait(
            $this->dispatcher,
            $connector,
            'drill_holes.list@1',
            ['limit' => $limit],
            $context->userId,
        );

        $this->assertOk($command);

        return $command->result_json['items'] ?? [];
    }

    public function find(TenantContext $context, string $holeId): array
    {
        $connector = $this->resolveConnector($context->tenantId);

        $command = $this->dispatchAndWait(
            $this->dispatcher,
            $connector,
            'drill_holes.get@1',
            ['id' => $holeId],
            $context->userId,
        );

        $this->assertOk($command);

        return $command->result_json['item'] ?? [];
    }
}
