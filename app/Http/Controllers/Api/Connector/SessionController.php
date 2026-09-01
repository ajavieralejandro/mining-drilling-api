<?php

namespace App\Http\Controllers\Api\Connector;

use App\Http\Controllers\Controller;
use App\Models\Connector;
use App\Models\ConnectorSession;
use App\Support\TokenHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SessionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'protocol_version' => ['required', 'string'],
            'connector_id' => ['required', 'string'],
            'connector_token' => ['required', 'string'],
        ]);

        if ($data['protocol_version'] !== config('connector.protocol_version')) {
            return $this->error('INVALID_PAYLOAD', 'Unsupported protocol_version', 422);
        }

        $connector = Connector::query()->find($data['connector_id']);

        if ($connector === null || $connector->status === Connector::STATUS_REVOKED) {
            return $this->error('UNAUTHORIZED', 'Invalid connector credentials', 401);
        }

        if (! hash_equals($connector->connector_token_hash, TokenHasher::hash($data['connector_token']))) {
            return $this->error('UNAUTHORIZED', 'Invalid connector credentials', 401);
        }

        ConnectorSession::query()
            ->where('connector_id', $connector->id)
            ->where('status', ConnectorSession::STATUS_ACTIVE)
            ->update([
                'status' => ConnectorSession::STATUS_ENDED,
                'ended_at' => now(),
            ]);

        $session = ConnectorSession::create([
            'id' => (string) Str::ulid(),
            'connector_id' => $connector->id,
            'status' => ConnectorSession::STATUS_ACTIVE,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);

        $connector->forceFill([
            'status' => Connector::STATUS_ONLINE,
            'last_seen_at' => now(),
        ])->save();

        return response()->json([
            'protocol_version' => config('connector.protocol_version'),
            'session_id' => $session->id,
            'poll_path' => '/api/connector/v1/poll',
            'heartbeat_interval_seconds' => 15,
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
