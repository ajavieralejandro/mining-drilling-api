<?php

namespace App\Domain\Repositories;

use App\Support\TenantContext;

/**
 * Risks of the company's holes. They live in the client database and are
 * reached through its connector. A risk is reported open, can be mitigated
 * and is finally closed; it is never deleted.
 */
interface RiskStore
{
    /**
     * @param  list<string>  $statuses
     * @param  list<string>  $levels
     * @return array{items: list<array<string, mixed>>, total: int, counts: array<string, int>}
     */
    public function risks(TenantContext $context, ?string $holeId, array $statuses, array $levels, int $page, int $perPage, string $correlationId): array;

    /**
     * @param  array{level: string, body: string, depth_detected?: string|null, critical_distance?: string|null, recommended_action?: string|null}  $risk
     * @return array{item: array<string, mixed>, replayed: bool}
     */
    public function report(TenantContext $context, string $holeId, array $risk, string $idempotencyKey, string $correlationId): array;

    /**
     * Moves a risk to mitigated or closed.
     *
     * @return array<string, mixed>|null null when the risk does not exist
     */
    public function transition(TenantContext $context, string $riskId, string $to, ?string $notes, string $correlationId): ?array;
}
