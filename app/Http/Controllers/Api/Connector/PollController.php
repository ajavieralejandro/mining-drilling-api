<?php

namespace App\Http\Controllers\Api\Connector;

use App\Http\Controllers\Controller;
use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Models\ConnectorSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PollController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Connector $connector */
        $connector = $request->attributes->get('connector');

        $data = $request->validate([
            'protocol_version' => ['required', 'string'],
            'connector_id' => ['required', 'string'],
            'session_id' => ['required', 'string'],
            'wait_seconds' => ['nullable', 'integer', 'min:0', 'max:30'],
        ]);

        if ($data['connector_id'] !== $connector->id) {
            return $this->error('UNAUTHORIZED', 'connector_id mismatch', 401);
        }

        $session = ConnectorSession::query()
            ->where('id', $data['session_id'])
            ->where('connector_id', $connector->id)
            ->where('status', ConnectorSession::STATUS_ACTIVE)
            ->first();

        if ($session === null) {
            return $this->error('UNAUTHORIZED', 'Invalid session', 401);
        }

        $waitSeconds = $data['wait_seconds'] ?? 0;
        $deadline = microtime(true) + $waitSeconds;

        do {
            $commands = ConnectorCommand::query()
                ->where('connector_id', $connector->id)
                ->where('status', ConnectorCommand::STATUS_PENDING)
                ->where('deadline_at', '>', now())
                ->orderBy('dispatched_at')
                ->limit(5)
                ->get();

            if ($commands->isNotEmpty()) {
                break;
            }

            if ($waitSeconds <= 0 || microtime(true) >= $deadline) {
                break;
            }

            usleep(200_000);
        } while (microtime(true) < $deadline);

        $payload = [];

        foreach ($commands as $command) {
            $command->forceFill([
                'status' => ConnectorCommand::STATUS_DELIVERED,
            ])->save();

            $payload[] = [
                'protocol_version' => config('connector.protocol_version'),
                'request_id' => $command->request_id,
                'correlation_id' => $command->correlation_id,
                'connector_id' => $command->connector_id,
                'tenant_id' => $command->tenant_id,
                'op' => $command->op,
                'payload' => $command->payload_json,
                'deadline' => $command->deadline_at->toIso8601String(),
            ];
        }

        $connector->forceFill(['last_seen_at' => now()])->save();

        return response()->json([
            'protocol_version' => config('connector.protocol_version'),
            'commands' => $payload,
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
