<?php

namespace App\Domain\Repositories;

use App\Support\TenantContext;

/**
 * Drilling plans, their platforms and the holes drilled from each platform.
 * They live in the client database and are reached through its connector.
 * A null or false return means "does not exist"; a broken rule is a
 * ValidationException on the reported field.
 */
interface PlanStore
{
    /** @return array{items: list<array<string, mixed>>} */
    public function plans(TenantContext $context, ?string $mineId, ?string $status, string $correlationId): array;

    /** @return array<string, mixed>|null the plan with its platforms */
    public function plan(TenantContext $context, string $id, string $correlationId): ?array;

    /**
     * @param  array<string, string|null>  $fields
     * @return array<string, mixed>|null
     */
    public function savePlan(TenantContext $context, ?string $id, array $fields, string $correlationId): ?array;

    public function deletePlan(TenantContext $context, string $id, string $correlationId): bool;

    /** @return array<string, mixed>|null the platform with its holes and their geometry */
    public function platform(TenantContext $context, string $id, string $correlationId): ?array;

    /**
     * @param  array<string, string|null>  $fields
     * @return array<string, mixed>|null
     */
    public function savePlatform(TenantContext $context, ?string $id, array $fields, string $correlationId): ?array;

    public function deletePlatform(TenantContext $context, string $id, string $correlationId): bool;

    /**
     * @param  list<array{hole_id: string, sequence?: int|null, target?: string|null}>  $holes
     * @return array<string, mixed>|null the platform after the change
     */
    public function attachHoles(TenantContext $context, string $platformId, array $holes, string $correlationId): ?array;

    public function detachHole(TenantContext $context, string $platformId, string $holeId, string $correlationId): bool;
}
