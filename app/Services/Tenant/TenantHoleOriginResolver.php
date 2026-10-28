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
     * Resuelve y valida en un único paso el pozo y su origen físico a través del Data Gateway.
     *
     * 1. Consulta el pozo mediante TenantHoleRepositoryInterface::find usando el contexto autorizado.
     * 2. Valida que la respuesta contiene el pozo solicitado y no está vacía.
     * 3. Obtiene el origin_id confirmado por la respuesta del Gateway.
     * 4. Rechaza un origin_id ausente/vacío (ORIGIN_NOT_INFORMED) o inválido (INVALID_ORIGIN_ID).
     * 5. Si existe una configuración activa aplicada al mismo Connector en tenant_source_configs,
     *    comprueba coherencia con su fingerprint (ORIGIN_MISMATCH).
     * 6. Si el cliente solicita un origin_id, exige coincidencia exacta con el confirmado.
     * 7. Devolver juntos el pozo validado, su origen y los identificadores de petición/correlación.
     *
     * @return array{
     *     item: array<string, mixed>,
     *     origin_id: string,
     *     connector: ?Connector,
     *     request_id: ?string,
     *     correlation_id: string,
     * }
     *
     * @throws GatewayException|ValidationException
     */
    public function resolveHoleAndOrigin(
        TenantContext $context,
        string $holeId,
        string $correlationId,
        ?string $requestedOriginId = null,
    ): array {
        // a. Consultar el pozo mediante TenantHoleRepositoryInterface::find usando el contexto autorizado
        $result = $this->holeRepository->find($context, $correlationId, $holeId);

        $item = $result['item'] ?? [];

        // b. Validar que la respuesta contiene el pozo solicitado
        if (empty($item) || ! isset($item['id']) || (string) $item['id'] !== (string) $holeId) {
            throw new GatewayException(
                'NOT_FOUND',
                "El pozo '{$holeId}' no fue encontrado en el origen del cliente.",
                404,
                $result['request_id'] ?? null,
                $correlationId,
            );
        }

        // c. Obtener el origin_id confirmado por esa misma respuesta
        $confirmedOrigin = $result['origin_id'] ?? $item['origin_id'] ?? null;

        // d. Rechazar un origin_id ausente, vacío o inválido con un error explícito
        if (! is_string($confirmedOrigin) || trim($confirmedOrigin) === '') {
            throw new GatewayException(
                'ORIGIN_NOT_INFORMED',
                'El Connector respondió sin identidad de origen (origin_id ausente en la respuesta del Gateway).',
                422,
                $result['request_id'] ?? null,
                $correlationId,
            );
        }

        $confirmedOrigin = trim($confirmedOrigin);

        if (! preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/', $confirmedOrigin)) {
            throw new GatewayException(
                'INVALID_ORIGIN_ID',
                'La identidad de origen devuelta por el Connector es inválida.',
                422,
                $result['request_id'] ?? null,
                $correlationId,
            );
        }

        // Coherencia con la configuración aplicada al mismo Connector
        $connector = $result['connector'] ?? null;
        if ($connector instanceof Connector) {
            $this->assertCoherenceWithAppliedConfig(
                $context->tenantId,
                $connector,
                $confirmedOrigin,
                $result['request_id'] ?? null,
                $correlationId,
            );
        }

        // e. Si el cliente envía origin_id, exigir coincidencia con el confirmado
        if ($requestedOriginId !== null && trim($requestedOriginId) !== '') {
            if (trim($requestedOriginId) !== $confirmedOrigin) {
                throw ValidationException::withMessages([
                    'origin_id' => 'El origin_id especificado no coincide con el origen confirmado del pozo.',
                ]);
            }
        }

        // f. Devolver juntos el pozo validado, su origen y los identificadores de petición/correlación
        return [
            'item' => $item,
            'origin_id' => $confirmedOrigin,
            'connector' => $connector,
            'request_id' => $result['request_id'] ?? null,
            'correlation_id' => $result['correlation_id'] ?? $correlationId,
        ];
    }

    /**
     * Contrasta la identidad de origen con la configuración realmente aplicada al MISMO Connector.
     * No exige configuración previa en web si el conector está configurado directamente en local/remoto,
     * pero si existe una configuración activa aplicada, previene discrepancias antes de operar.
     *
     * @throws GatewayException
     */
    private function assertCoherenceWithAppliedConfig(
        string $tenantId,
        Connector $connector,
        string $confirmedOrigin,
        ?string $requestId,
        string $correlationId,
    ): void {
        if (! class_exists(TenantSourceConfig::class) || ! Schema::hasTable('tenant_source_configs')) {
            return;
        }

        // Buscar únicamente configuración ACTIVA y aplicada al MISMO Connector
        $appliedConfig = TenantSourceConfig::query()
            ->where('tenant_id', $tenantId)
            ->where('connector_id', $connector->id)
            ->where('state', TenantSourceConfig::STATE_ACTIVE)
            ->orderByDesc('version')
            ->first();

        if ($appliedConfig === null) {
            // No hay configuración web activa aplicada; se reconoce el origen directo del conector
            return;
        }

        if (is_string($appliedConfig->fingerprint) && $appliedConfig->fingerprint !== '') {
            if ($appliedConfig->fingerprint !== $confirmedOrigin) {
                throw new GatewayException(
                    'ORIGIN_MISMATCH',
                    'La identidad de origen reportada por el Gateway no coincide con la configuración activa aplicada al Connector.',
                    422,
                    $requestId,
                    $correlationId,
                );
            }
        }
    }

    /**
     * Resuelve el origin_id autorizado de la organización a partir de la configuración
     * activa en el servidor (TenantSourceConfig::STATE_ACTIVE o reported_source del Connector).
     *
     * @throws GatewayException|ValidationException
     */
    public function resolveAuthorizedOrigin(
        TenantContext $context,
        ?string $requestedOriginId = null,
        ?string $correlationId = null
    ): string {
        $authorizedOrigin = $this->determineTenantAuthorizedOrigin($context->tenantId, $correlationId);

        if ($requestedOriginId !== null && trim($requestedOriginId) !== '') {
            if (trim($requestedOriginId) !== $authorizedOrigin) {
                throw ValidationException::withMessages([
                    'origin_id' => 'El origin_id especificado no coincide con el origen autorizado de la organización.',
                ]);
            }
        }

        return $authorizedOrigin;
    }

    /**
     * Determina la identidad estable (fingerprint SHA-256) del origen autorizado del tenant.
     * Consulta la configuración activa en tenant_source_configs o el origen informado por el conector más reciente.
     *
     * @throws GatewayException
     */
    public function determineTenantAuthorizedOrigin(string $tenantId, ?string $correlationId = null): string
    {
        // 1. Configuración activa en tenant_source_configs
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
        $connector = Connector::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('last_seen_at')
            ->first();

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
     * @return array{item: array<string, mixed>, origin_id: string, connector?: ?Connector, request_id: ?string, correlation_id: string}
     *
     * @throws GatewayException|ValidationException
     */
    public function verifyHoleInGateway(
        TenantContext $context,
        string $correlationId,
        string $holeId,
        string $authorizedOrigin
    ): array {
        $resolved = $this->resolveHoleAndOrigin($context, $holeId, $correlationId, $authorizedOrigin);

        return [
            'item' => $resolved['item'],
            'origin_id' => $resolved['origin_id'],
            'connector' => $resolved['connector'] ?? null,
            'request_id' => $resolved['request_id'],
            'correlation_id' => $resolved['correlation_id'],
        ];
    }
}
