<?php

namespace App\Domain\Repositories;

use App\Support\TenantContext;

/**
 * Projects, mines and hole ↔ mine links of a tenant. They live in the
 * client database and are reached through its connector; UndSurf keeps no
 * copy. A null or false return means "does not exist"; a broken rule is a
 * ValidationException on the reported field.
 *
 * Rows come back in the connector's shape (see
 * undersurf-connector/internal/protocol/org.go); the presenters in
 * App\Http\Resources\Tenant\OrganizationPresenter turn them into the API
 * contract.
 */
interface OrganizationStore
{
    /** @return list<array<string, mixed>> */
    public function listProjects(TenantContext $context, ?string $status, bool $includeMines, bool $includeHoles, string $correlationId): array;

    /** @return array<string, mixed>|null the project with its mines and their holes */
    public function getProject(TenantContext $context, string $id, string $correlationId): ?array;

    /**
     * Creates ($id null) or updates a project. $fields holds only what
     * changes; a null value clears the column.
     *
     * @param  array<string, string|null>  $fields
     * @return array<string, mixed>|null null when the project to update does not exist
     */
    public function saveProject(TenantContext $context, ?string $id, array $fields, string $correlationId): ?array;

    /** Removes the project with its mines and their hole links. */
    public function deleteProject(TenantContext $context, string $id, string $correlationId): bool;

    /** @return list<array<string, mixed>> */
    public function listMines(TenantContext $context, ?string $projectId, ?string $status, bool $includeHoles, string $correlationId): array;

    /** @return array<string, mixed>|null the mine with its project and holes */
    public function getMine(TenantContext $context, string $id, string $correlationId): ?array;

    /**
     * @param  array<string, string|null>  $fields
     * @return array<string, mixed>|null
     */
    public function saveMine(TenantContext $context, ?string $id, array $fields, string $correlationId): ?array;

    public function deleteMine(TenantContext $context, string $id, string $correlationId): bool;

    /** @return list<array<string, mixed>>|null null when the mine does not exist */
    public function mineHoles(TenantContext $context, string $mineId, string $correlationId): ?array;

    /**
     * Links holes to a mine; a hole linked elsewhere moves. Each returned
     * link carries `created` (true when the hole had no mine before).
     *
     * @param  list<array{hole_id: string, origin_id?: string|null, status?: string|null, notes?: string|null}>  $holes
     * @return list<array<string, mixed>>|null null when the mine does not exist
     */
    public function attachHoles(TenantContext $context, string $mineId, array $holes, string $correlationId): ?array;

    public function detachHole(TenantContext $context, string $mineId, string $holeId, string $correlationId): bool;

    /**
     * Where each hole sits in the hierarchy. Holes without a mine are absent.
     *
     * @param  list<string>  $holeIds
     * @return array<string, array{mine: array<string, mixed>, project: array<string, mixed>|null}>
     */
    public function lookupHoles(TenantContext $context, array $holeIds, string $correlationId): array;
}
