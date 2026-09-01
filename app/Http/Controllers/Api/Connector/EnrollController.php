<?php

namespace App\Http\Controllers\Api\Connector;

use App\Http\Controllers\Controller;
use App\Models\Connector;
use App\Models\ConnectorEnrollmentToken;
use App\Services\Connector\PlatformAuditor;
use App\Support\TokenHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnrollController extends Controller
{
    public function __invoke(Request $request, PlatformAuditor $auditor): JsonResponse
    {
        $data = $request->validate([
            'protocol_version' => ['required', 'string'],
            'enrollment_token' => ['required', 'string'],
            'connector_label' => ['nullable', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:64'],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string'],
        ]);

        if ($data['protocol_version'] !== config('connector.protocol_version')) {
            return $this->error('INVALID_PAYLOAD', 'Unsupported protocol_version', 422);
        }

        $hash = TokenHasher::hash($data['enrollment_token']);

        $result = DB::transaction(function () use ($data, $hash, $auditor) {
            $token = ConnectorEnrollmentToken::query()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($token === null) {
                return ['error' => ['code' => 'UNAUTHORIZED', 'message' => 'Invalid enrollment token', 'status' => 401]];
            }

            if ($token->consumed_at !== null) {
                return ['error' => ['code' => 'UNAUTHORIZED', 'message' => 'Enrollment token already used', 'status' => 401]];
            }

            if ($token->expires_at->isPast()) {
                return ['error' => ['code' => 'UNAUTHORIZED', 'message' => 'Enrollment token expired', 'status' => 401]];
            }

            $connectorToken = TokenHasher::generate('cct');
            $connectorId = (string) Str::ulid();

            $connector = Connector::create([
                'id' => $connectorId,
                'tenant_id' => $token->tenant_id,
                'label' => $data['connector_label'] ?? null,
                'status' => Connector::STATUS_PENDING_AUTH,
                'version' => $data['version'] ?? null,
                'capabilities' => $data['capabilities'] ?? [],
                'connector_token_hash' => TokenHasher::hash($connectorToken),
            ]);

            $token->forceFill([
                'consumed_at' => now(),
                'created_connector_id' => $connector->id,
            ])->save();

            $auditor->log(
                event: 'connector_enrolled',
                actorType: 'connector',
                actorId: $connector->id,
                tenantId: $connector->tenant_id,
                connectorId: $connector->id,
                resultSummary: ['status' => 'enrolled'],
            );

            return [
                'connector' => $connector,
                'connector_token' => $connectorToken,
            ];
        });

        if (isset($result['error'])) {
            return $this->error($result['error']['code'], $result['error']['message'], $result['error']['status']);
        }

        /** @var Connector $connector */
        $connector = $result['connector'];

        return response()->json([
            'protocol_version' => config('connector.protocol_version'),
            'tenant_id' => $connector->tenant_id,
            'connector_id' => $connector->id,
            'connector_token' => $result['connector_token'],
            'expires_at' => null,
        ], 201);
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
