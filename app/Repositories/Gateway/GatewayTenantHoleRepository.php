<?php

namespace App\Repositories\Gateway;

use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Models\ConnectorCommand;
use App\Services\Connector\CommandDispatcher;
use App\Support\GatewayException;
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
            'items' => $this->requireListItems($command),
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

    /**
     * drill_holes.list@1 result must be {items: [ {id, code, status}, ... ]}.
     * An empty list is valid; a missing or non-list items key is not.
     *
     * @return array<int, array<string, mixed>>
     */
    private function requireListItems(ConnectorCommand $command): array
    {
        $items = $command->result_json['items'] ?? null;

        if (! is_array($items) || ! array_is_list($items)) {
            throw new GatewayException(
                'INVALID_RESULT',
                'Connector returned an invalid result',
                502,
                $command->request_id,
                $command->correlation_id,
            );
        }

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['id'], $item['code'], $item['status'])) {
                throw new GatewayException(
                    'INVALID_RESULT',
                    'Connector returned an invalid result',
                    502,
                    $command->request_id,
                    $command->correlation_id,
                );
            }
        }

        return $items;
    }
}
