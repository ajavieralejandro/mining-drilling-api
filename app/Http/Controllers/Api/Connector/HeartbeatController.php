<?php

namespace App\Http\Controllers\Api\Connector;

use App\Http\Controllers\Controller;
use App\Models\Connector;
use App\Models\ConnectorSession;
use App\Services\Connector\ConnectorPresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeartbeatController extends Controller
{
    public function __invoke(Request $request, ConnectorPresence $presence): JsonResponse
    {
        /** @var Connector $connector */
        $connector = $request->attributes->get('connector');

        $data = $request->validate([
            'protocol_version' => ['required', 'string'],
            'connector_id' => ['required', 'string'],
            'session_id' => ['required', 'string'],
            'version' => ['nullable', 'string', 'max:64'],
            'capabilities' => ['nullable', 'array'],
            'status' => ['nullable', 'string'],
            'adapter_status' => ['nullable', 'array'],
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

        $presence->markOnline(
            $connector,
            $data['version'] ?? null,
            $data['capabilities'] ?? null,
        );
        $presence->refreshSessionHeartbeat($session);

        return response()->json([
            'protocol_version' => config('connector.protocol_version'),
            'status' => 'ok',
            'server_time' => now()->toIso8601String(),
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
