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
     * @return array<int, array{id: string, code: string, status: string}>
     */
    public function list(TenantContext $context, int $limit = 10): array;

    /**
     * @return array{id: string, code: string, status: string}
     *
     * @throws \App\Support\GatewayException with code NOT_FOUND (HTTP 404)
     *         when $holeId does not exist in this tenant's own database —
     *         including when it exists under a colliding id in a *different*
     *         tenant's database, which this repository can never see.
     */
    public function find(TenantContext $context, string $holeId): array;
}
