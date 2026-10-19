<?php

namespace App\Repositories\Gateway;

use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Services\Connector\CommandDispatcher;
use App\Services\Source\SourceConfigService;
use App\Support\GatewayException;
use App\Support\TenantContext;

class GatewayTenantHoleRepository implements TenantHoleRepositoryInterface
{
    use GatewayCommandTrait;

    public function __construct(
        private readonly CommandDispatcher $dispatcher,
        private readonly SourceConfigService $sources,
    ) {}

    public function list(TenantContext $context, string $correlationId, int $limit = 10): array
    {
        $connector = $this->resolveConnector($context->tenantId, $correlationId);
        $command = $this->dispatchHole($connector, 'drill_holes.list@1', ['limit' => $limit], $context->userId, $correlationId);
        $this->assertOk($command);

        return [
            'items' => $this->requireListItems($command),
            'origin_id' => $this->originId($command),
            'request_id' => $command->request_id,
            'correlation_id' => $command->correlation_id,
        ];
    }

    public function find(TenantContext $context, string $correlationId, string $holeId): array
    {
        $connector = $this->resolveConnector($context->tenantId, $correlationId);
        $command = $this->dispatchHole($connector, 'drill_holes.get@1', ['id' => $holeId], $context->userId, $correlationId);
        $this->assertOk($command);

        return [
            'item' => $command->result_json['item'] ?? [],
            'origin_id' => $this->originId($command),
            'request_id' => $command->request_id,
            'correlation_id' => $command->correlation_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchHole(Connector $connector, string $op, array $payload, int $userId, string $correlationId): ConnectorCommand
    {
        try {
            $command = $this->dispatchAndWait($this->dispatcher, $connector, $op, $payload, $userId, $correlationId);
        } catch (GatewayException $e) {
            $this->sources->recordHoleQuery($connector, null, $e->errorCode);
            throw $e;
        }

        $this->sources->recordHoleQuery(
            $connector,
            $command,
            $command->status === ConnectorCommand::STATUS_COMPLETED ? null : $command->error_code,
        );

        return $command;
    }

    private function originId(ConnectorCommand $command): ?string
    {
        $origin = $command->result_json['origin_id'] ?? null;

        return is_string($origin) && $origin !== '' ? $origin : null;
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
