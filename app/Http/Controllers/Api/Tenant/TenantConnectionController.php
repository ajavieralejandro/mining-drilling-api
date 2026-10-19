<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Connector;
use App\Models\Tenant;
use App\Services\Source\SourceConfigService;
use App\Support\GatewayException;
use App\Support\RequestCorrelation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only view of the tenant resolved from the authenticated membership
 * and the Connectors registered for that tenant in Laravel's control plane.
 *
 * This does not call the Connector and does not read the client's source
 * database. A recent heartbeat only means the Connector spoke to this API.
 * Source-engine is included only when the Connector registered a known
 * adapter capability. Installation location is not stored here, so it is
 * omitted rather than sent empty.
 */
class TenantConnectionController extends Controller
{
    /**
     * Capabilities the Connector may announce that name the source engine.
     * Anything else in the capabilities list stays on the server.
     *
     * @var array<string, string>
     */
    private const SOURCE_ENGINES = [
        'adapter.mysql' => 'mysql',
        'adapter.postgres' => 'postgres',
    ];

    public function show(Request $request, TenantContext $context, SourceConfigService $configs): JsonResponse
    {
        $tenant = Tenant::query()->find($context->tenantId);

        if ($tenant === null) {
            return (new GatewayException(
                'NOT_FOUND',
                'Tenant not found.',
                404,
                null,
                RequestCorrelation::currentId(),
            ))->toResponse();
        }

        $connectors = Connector::query()
            ->where('tenant_id', $tenant->id)
            ->get(['id', 'label', 'status', 'capabilities', 'last_seen_at'])
            ->sortByDesc(fn (Connector $connector) => $connector->last_seen_at?->getTimestamp() ?? 0)
            ->values()
            ->map(fn (Connector $connector) => $this->publicConnector($connector))
            ->all();

        $data = [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'connectors' => $connectors,
            'can_manage_source' => $context->role === 'admin',
            'observed_at' => now()->toIso8601String(),
        ];

        if ($context->role === 'admin') {
            try {
                $data['source'] = $configs->present($context, $request->isSecure());
            } catch (GatewayException $e) {
                if ($e->errorCode !== 'CONNECTOR_OFFLINE') {
                    return $e->toResponse();
                }
                $data['source'] = null;
            }
        }

        return response()->json(['data' => $data]);
    }

    /**
     * @return array<string, mixed>
     */
    private function publicConnector(Connector $connector): array
    {
        $payload = [
            'id' => $connector->id,
            'label' => $connector->label,
            'status' => $connector->status,
            'communicating' => $connector->isOnline(),
            'last_seen_at' => $connector->last_seen_at?->toIso8601String(),
        ];

        $engine = $this->registeredSourceEngine($connector->capabilities);

        if ($engine !== null) {
            $payload['source_engine'] = $engine;
        }

        return $payload;
    }

    private function registeredSourceEngine(mixed $capabilities): ?string
    {
        if (! is_array($capabilities)) {
            return null;
        }

        foreach ($capabilities as $key => $value) {
            $candidate = is_string($value) ? $value : (is_string($key) ? $key : null);

            if ($candidate !== null && isset(self::SOURCE_ENGINES[$candidate])) {
                return self::SOURCE_ENGINES[$candidate];
            }
        }

        return null;
    }
}
