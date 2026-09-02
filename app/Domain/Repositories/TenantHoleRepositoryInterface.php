<?php

namespace App\Domain\Repositories;

use App\Support\TenantContext;

/**
 * Persistence port for pozos that live in the CLIENT's own database,
 * reached only through the Data Gateway (never through Laravel's own DB).
 * This is deliberately a separate interface from DrillHoleRepositoryInterface:
 * that one models Laravel's own Eloquent-backed drill_holes table (still
 * used by the legacy /api/drill-holes* screens); this one models data that
 * genuinely does not live in Laravel's ORM, so it is typed as plain arrays,
 * not Eloquent models. See docs/architecture/customer-data-architecture.md
 * and docs/sprints/distributed-data-vertical-slice.md.
 */
interface TenantHoleRepositoryInterface
{
    /**
     * @param  string  $correlationId  Canonical correlation id for this
     *         HTTP request (see App\Support\RequestCorrelation) — carried
     *         through to the Connector dispatch and back so it survives
     *         even a failure that happens before a command exists.
     * @return array{items: array<int, array{id: string, code: string, status: string}>, request_id: ?string, correlation_id: string}
     */
    public function list(TenantContext $context, string $correlationId, int $limit = 10): array;

    /**
     * @param  string  $correlationId  See list().
     * @return array{item: array{id: string, code: string, status: string}, request_id: ?string, correlation_id: string}
     *
     * @throws \App\Support\GatewayException with code NOT_FOUND (HTTP 404)
     *         when $holeId does not exist in this tenant's own database —
     *         including when it exists under a colliding id in a *different*
     *         tenant's database, which this repository can never see.
     */
    public function find(TenantContext $context, string $correlationId, string $holeId): array;
}
