<?php

namespace App\Http\Controllers\Api\Connector;

use App\Http\Controllers\Controller;
use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Services\Connector\PlatformAuditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResultController extends Controller
{
    public function __invoke(Request $request, PlatformAuditor $auditor): JsonResponse
    {
        /** @var Connector $connector */
        $connector = $request->attributes->get('connector');

        $data = $request->validate([
            'protocol_version' => ['required', 'string'],
            'request_id' => ['required', 'string'],
            'correlation_id' => ['required', 'string'],
            'connector_id' => ['required', 'string'],
            'tenant_id' => ['required', 'string'],
            'op' => ['required', 'string'],
            'status' => ['required', 'string', 'in:ok,error'],
            'payload' => ['nullable', 'array'],
            'error' => ['nullable', 'array'],
            'error.code' => ['required_if:status,error', 'string'],
            'error.message' => ['nullable', 'string'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($data['connector_id'] !== $connector->id) {
            return $this->error('UNAUTHORIZED', 'connector_id mismatch', 401);
        }

        $command = ConnectorCommand::query()
            ->where('request_id', $data['request_id'])
            ->where('connector_id', $connector->id)
            ->first();

        if ($command === null) {
            return $this->error('INVALID_PAYLOAD', 'Unknown request_id', 422);
        }

        if (in_array($command->status, [
            ConnectorCommand::STATUS_COMPLETED,
            ConnectorCommand::STATUS_FAILED,
            ConnectorCommand::STATUS_TIMEOUT,
        ], true)) {
            return response()->json([
                'protocol_version' => config('connector.protocol_version'),
                'status' => 'ok',
                'message' => 'already recorded',
            ]);
        }

        $ok = $data['status'] === 'ok';
        $errorCode = $ok ? null : ($data['error']['code'] ?? 'ADAPTER_ERROR');

        $command->forceFill([
            'status' => $ok ? ConnectorCommand::STATUS_COMPLETED : ConnectorCommand::STATUS_FAILED,
            'result_json' => $ok ? ($data['payload'] ?? []) : null,
            'error_code' => $errorCode,
            'completed_at' => now(),
            'duration_ms' => $data['duration_ms'] ?? null,
        ])->save();

        $auditor->log(
            event: $ok ? 'completed' : 'failed',
            requestId: $command->request_id,
            correlationId: $command->correlation_id,
            actorType: 'connector',
            actorId: $connector->id,
            tenantId: $command->tenant_id,
            connectorId: $connector->id,
            op: $command->op,
            resultSummary: [
                'status' => $data['status'],
                'error_code' => $errorCode,
                'item_count' => $ok ? count($data['payload']['items'] ?? []) : null,
            ],
            durationMs: $command->duration_ms,
        );

        return response()->json([
            'protocol_version' => config('connector.protocol_version'),
            'status' => 'ok',
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'protocol_version' => config('connector.protocol_version'),
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
