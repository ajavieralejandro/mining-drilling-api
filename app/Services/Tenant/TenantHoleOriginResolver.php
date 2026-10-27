<?php

namespace App\Services\Tenant;

use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Models\Connector;
use App\Models\TenantHoleOperationalState;
use App\Models\TenantSourceConfig;
use App\Support\GatewayException;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TenantHoleOriginResolver
{
    public function __construct(
        private readonly ?TenantHoleRepositoryInterface $holeRepository = null,
    ) {}

    /**
     * Resuelve y valida el origin_id autorizado para la organización y el pozo.
     * Si la petición solicita un origin_id explícito, comprueba que coincida con el origen autorizado.
     *
     * @throws ValidationException|GatewayException
     */
    public function resolveAuthorizedOrigin(
        TenantContext $context,
        string $holeId,
        ?string $requestedOriginId = null,
        ?string $correlationId = null
    ): string {
        $authorizedOrigin = $this->determineTenantAuthorizedOrigin($context->tenantId);

        if ($requestedOriginId !== null && $requestedOriginId !== '') {
            if ($authorizedOrigin !== null && $requestedOriginId !== $authorizedOrigin) {
                throw ValidationException::withMessages([
                    'origin_id' => 'El pozo no pertenece al origen autorizado para esta organización.',
                ]);
            }
            return $requestedOriginId;
        }

        return $authorizedOrigin ?? 'default-origin';
    }

    /**
     * Determina la identidad estable (fingerprint SHA-256) del origen autorizado del tenant.
     */
    public function determineTenantAuthorizedOrigin(string $tenantId): ?string
    {
        // 1. Si existe la tabla tenant_source_configs (rama feat/source-origin-config o servidor), buscar la activa
        if (class_exists(TenantSourceConfig::class) && Schema::hasTable('tenant_source_configs')) {
            $active = TenantSourceConfig::query()
                ->where('tenant_id', $tenantId)
                ->where('state', TenantSourceConfig::STATE_ACTIVE)
                ->first();

            if ($active && ! empty($active->fingerprint)) {
                return (string) $active->fingerprint;
            }
        }

        // 2. Si el conector informó su origen en connectors.reported_source o last_hole_query
        $connector = Connector::query()->where('tenant_id', $tenantId)->first();
        if ($connector) {
            if (isset($connector->reported_source['fingerprint']) && is_string($connector->reported_source['fingerprint'])) {
                return $connector->reported_source['fingerprint'];
            }
            if (isset($connector->last_hole_query['origin_id']) && is_string($connector->last_hole_query['origin_id'])) {
                return $connector->last_hole_query['origin_id'];
            }
        }

        // 3. Revisar si ya existen estados operacionales registrados para este tenant
        $existing = TenantHoleOperationalState::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('origin_id')
            ->where('origin_id', '!=', 'default-origin')
            ->latest('updated_at')
            ->first();

        if ($existing) {
            return (string) $existing->origin_id;
        }

        return null;
    }

    /**
     * Opcional: comprueba la pertenencia del pozo al Data Gateway cuando está disponible.
     * Si el pozo no existe en la base del cliente, el Gateway arroja HTTP 404 (GatewayException).
     *
     * @return array<string, mixed>|null Datos del pozo si el gateway respondió.
     */
    public function verifyHoleInGateway(TenantContext $context, string $correlationId, string $holeId, string $originId): ?array
    {
        if ($this->holeRepository === null) {
            return null;
        }

        try {
            $result = $this->holeRepository->find($context, $correlationId, $holeId);

            if (! empty($result['origin_id']) && $result['origin_id'] !== $originId && $originId !== 'default-origin') {
                throw ValidationException::withMessages([
                    'origin_id' => 'El pozo pertenece a un origen distinto al autorizado.',
                ]);
            }

            return $result['item'] ?? null;
        } catch (GatewayException $e) {
            // Si el Gateway reporta NOT_FOUND (404), el pozo no existe en el origen
            if ($e->statusCode === 404) {
                throw $e;
            }

            // Si el gateway no responde (timeout/offline), permitimos continuar si ya existe estado local
            return null;
        }
    }
}
