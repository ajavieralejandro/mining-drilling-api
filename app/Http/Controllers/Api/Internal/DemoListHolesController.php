<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Services\Connector\CommandDispatcher;
use App\Services\Connector\ConnectorPresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DemoListHolesController extends Controller
{
    public function __invoke(
        Request $request,
        CommandDispatcher $dispatcher,
        ConnectorPresence $presence,
    ): JsonResponse {
        $data = $request->validate([
            'tenant_id' => ['nullable', 'string'],
            'connector_id' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $limit = $data['limit'] ?? 10;

        $query = Connector::query()->orderByDesc('last_seen_at');

        if (! empty($data['connector_id'])) {
            $query->where('id', $data['connector_id']);
        }

        if (! empty($data['tenant_id'])) {
            $query->where('tenant_id', $data['tenant_id']);
        }

        /** @var Connector|null $connector */
        $connector = $query->first();

        if ($connector === null) {
            return $this->error('CONNECTOR_OFFLINE', 'No connector registered', null, null, 503);
        }

        if (! $presence->assertOnline($connector)) {
            return $this->error(
                'CONNECTOR_OFFLINE',
                'Connector is offline',
                null,
                null,
                503,
                $connector->id,
                $connector->tenant_id,
            );
        }

        try {
            $command = $dispatcher->dispatch(
                connector: $connector,
                op: 'drill_holes.list@1',
                payload: ['limit' => $limit],
                actorType: 'demo',
                actorId: 'internal',
            );
        } catch (RuntimeException $e) {
            $code = $e->getMessage();
            if ($code === 'CONNECTOR_OFFLINE') {
                return $this->error($code, 'Connector is offline', null, null, 503, $connector->id, $connector->tenant_id);
            }
            if ($code === 'UNSUPPORTED_OP') {
                return $this->error($code, 'Unsupported operation', null, null, 422, $connector->id, $connector->tenant_id);
            }

            return $this->error('ADAPTER_ERROR', 'Failed to dispatch command', null, null, 500, $connector->id, $connector->tenant_id);
        }

        $command = $dispatcher->waitForResult($command);

        if ($command->status === ConnectorCommand::STATUS_TIMEOUT) {
            return $this->error(
                'TIMEOUT',
                'Connector did not respond before deadline',
                $command->request_id,
                $command->correlation_id,
                504,
                $connector->id,
                $connector->tenant_id,
            );
        }

        if ($command->status === ConnectorCommand::STATUS_FAILED) {
            $code = $command->error_code ?: 'ADAPTER_ERROR';

            return $this->error(
                $code,
                'Connector reported an error',
                $command->request_id,
                $command->correlation_id,
                502,
                $connector->id,
                $connector->tenant_id,
            );
        }

        return response()->json([
            'request_id' => $command->request_id,
            'correlation_id' => $command->correlation_id,
            'tenant_id' => $command->tenant_id,
            'connector_id' => $command->connector_id,
            'op' => $command->op,
            'items' => $command->result_json['items'] ?? [],
        ]);
    }

    private function error(
        string $code,
        string $message,
        ?string $requestId,
        ?string $correlationId,
        int $status,
        ?string $connectorId = null,
        ?string $tenantId = null,
    ): JsonResponse {
        return response()->json([
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'tenant_id' => $tenantId,
            'connector_id' => $connectorId,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
