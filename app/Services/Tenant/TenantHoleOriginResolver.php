<?php

namespace App\Services\Tenant;

use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Models\Connector;
use App\Models\TenantSourceConfig;
use App\Support\GatewayException;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TenantHoleOriginResolver
{
    public function __construct(
        private readonly TenantHoleRepositoryInterface $holeRepository,
    ) {}

    /**
     * Resuelve el origin_id autorizado de la organización a partir de la configuración
     * activa en el servidor (TenantSourceConfig::STATE_ACTIVE o reported_source del Connector).
     *
     * Si la petición incluye un origin_id explícito, se verifica que coincida exactamente
     * con el autorizado; nunca se utiliza para establecer o reemplazar el origen.
     *
     * @throws GatewayException|ValidationException
     */
    public function resolveAuthorizedOrigin(
        TenantContext $context,
        ?string $requestedOriginId = null,
        ?string $correlationId = null
    ): string {
        $authorizedOrigin = $this->determineTenantAuthorizedOrigin($context->tenantId, $correlationId);

        if ($requestedOriginId !== null && $requestedOriginId !== '') {
            if ($requestedOriginId !== $authorizedOrigin) {
                throw ValidationException::withMessages([
                    'origin_id' => 'El origin_id especificado no coincide con el origen autorizado de la organización.',
                ]);
            }
        }

        return $authorizedOrigin;
    }

    /**
     * Determina la identidad estable (fingerprint SHA-256) del origen autorizado del tenant.
     * Consulta la configuración activa en tenant_source_configs o el origen informado por el Connector.
     * Si no existe configuración autorizada, arroja GatewayException (no recurre a defaults ni inferencias).
     *
     * @throws GatewayException
     */
    public function determineTenantAuthorizedOrigin(string $tenantId, ?string $correlationId = null): string
    {
        // 1. Configuración activa en tenant_source_configs (modelo y campos reales de feat/source-origin-config)
        if (class_exists(TenantSourceConfig::class) && Schema::hasTable('tenant_source_configs')) {
            $active = TenantSourceConfig::query()
                ->where('tenant_id', $tenantId)
                ->where('state', TenantSourceConfig::STATE_ACTIVE)
                ->orderByDesc('version')
                ->first();

            if ($active && is_string($active->fingerprint) && $active->fingerprint !== '') {
                return $active->fingerprint;
            }
        }

        // 2. Origen informado por el Connector mediante heartbeat / reported_source
        $connector = Connector::query()->where('tenant_id', $tenantId)->first();
        if ($connector && is_array($connector->reported_source)) {
            $reportedFingerprint = $connector->reported_source['fingerprint'] ?? null;
            if (is_string($reportedFingerprint) && $reportedFingerprint !== '') {
                return $reportedFingerprint;
            }
        }

        // 3. Si no se puede determinar la configuración autorizada, no se permite continuar ni usar defaults
        throw new GatewayException(
            'ORIGIN_NOT_CONFIGURED',
            'La organización no posee una base de origen configurada o autorizada en el Gateway.',
            422,
            null,
            $correlationId
        );
    }

    /**
     * Exige y valida la existencia física del pozo y su origen en el Data Gateway
     * antes de habilitar cualquier lectura o escritura operativa.
     *
     * Propaga sin suprimir errores de acceso, 404, desconexión del conector (503) o timeout (504).
     *
     * @return array{item: array<string, mixed>, origin_id: string, request_id: ?string, correlation_id: string}
     *
     * @throws GatewayException|ValidationException
     */
    public function verifyHoleInGateway(
        TenantContext $context,
        string $correlationId,
        string $holeId,
        string $authorizedOrigin
    ): array {
        $result = $this->holeRepository->find($context, $correlationId, $holeId);

        $gatewayOrigin = $result['origin_id'] ?? null;
        $item = $result['item'] ?? [];

        if (empty($item) || ! isset($item['id'])) {
            throw new GatewayException(
                'NOT_FOUND',
                "El pozo '{$holeId}' no fue encontrado en el origen del cliente.",
                404,
                $result['request_id'] ?? null,
                $correlationId
            );
        }

        // Si el Gateway reporta un origin_id explícito, comprobar coincidencia con el autorizado
        if (is_string($gatewayOrigin) && $gatewayOrigin !== '') {
            if ($gatewayOrigin !== $authorizedOrigin) {
                throw ValidationException::withMessages([
                    'origin_id' => 'El pozo reportado por el Gateway pertenece a un origen distinto al autorizado.',
                ]);
            }
        }

        return [
            'item' => $item,
            'origin_id' => is_string($gatewayOrigin) && $gatewayOrigin !== '' ? $gatewayOrigin : $authorizedOrigin,
            'request_id' => $result['request_id'] ?? null,
            'correlation_id' => $result['correlation_id'] ?? $correlationId,
        ];
    }
}
