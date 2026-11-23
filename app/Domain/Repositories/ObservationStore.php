<?php

namespace App\Domain\Repositories;

use App\Support\TenantContext;

/**
 * Hole observations, the company's own observation types and the hole
 * geometry the graphic views draw. All of it lives in the client database
 * and is reached through its connector; UndSurf keeps no copy.
 *
 * The observation log is append-only: there is no update or delete.
 */
interface ObservationStore
{
    /** @return list<array<string, mixed>> */
    public function types(TenantContext $context, bool $includeInactive, string $correlationId): array;

    /**
     * Creates ($id null) or updates a type. $fields holds only what changes
     * (code, name, active as "true"/"false", sort_order).
     *
     * @param  array<string, string|null>  $fields
     * @return array<string, mixed>|null null when the type to update does not exist
     */
    public function saveType(TenantContext $context, ?string $id, array $fields, string $correlationId): ?array;

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function observations(TenantContext $context, string $holeId, ?string $typeId, int $page, int $perPage, string $correlationId): array;

    /**
     * @return array{item: array<string, mixed>, replayed: bool}
     */
    public function record(TenantContext $context, string $holeId, string $typeId, string $body, ?string $depth, string $idempotencyKey, string $correlationId): array;

    /**
     * @return array{values: array<string, string|null>, missing: list<string>, current_depth: string|null, marks: list<array<string, mixed>>, risks: list<array<string, mixed>>}
     */
    public function geometry(TenantContext $context, string $holeId, string $correlationId): array;
}
