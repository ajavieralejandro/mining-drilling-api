<?php

namespace App\Http\Controllers\Api\Connector;

use App\Http\Controllers\Controller;
use App\Models\Connector;
use App\Models\TenantSourceConfig;
use App\Services\Connector\PlatformAuditor;
use App\Services\Source\SourceConfigService;
use App\Support\GatewayException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SourceConfigController extends Controller
{
    public function __construct(
        private readonly SourceConfigService $configs,
        private readonly PlatformAuditor $auditor,
    ) {}

    public function __invoke(Request $request, string $config): JsonResponse
    {
        /** @var Connector $connector */
        $connector = $request->attributes->get('connector');

        $row = TenantSourceConfig::query()
            ->whereKey($config)
            ->where('connector_id', $connector->id)
            ->where('tenant_id', $connector->tenant_id)
            ->whereIn('state', [
                TenantSourceConfig::STATE_DRAFT,
                TenantSourceConfig::STATE_TESTED,
                TenantSourceConfig::STATE_ACTIVE,
            ])
            ->first();

        if ($row === null) {
            return response()->json([
                'protocol_version' => config('connector.protocol_version'),
                'error' => ['code' => 'NOT_FOUND', 'message' => 'La configuración no está disponible para este Connector.'],
            ], 404);
        }

        try {
            $material = $this->configs->materialForConnector($row);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        $this->auditor->log(
            event: 'source_config.fetched',
            actorType: 'connector',
            actorId: $connector->id,
            tenantId: $connector->tenant_id,
            connectorId: $connector->id,
            op: 'source_config.fetch',
            resultSummary: [
                'version' => $row->version,
                'fingerprint' => $row->fingerprint,
                'engine' => $row->engine,
                'host' => $row->host,
                'port' => $row->port,
                'database' => $row->database_name,
            ],
        );

        return response()->json([
            'protocol_version' => config('connector.protocol_version'),
            'data' => $material,
        ]);
    }
}
