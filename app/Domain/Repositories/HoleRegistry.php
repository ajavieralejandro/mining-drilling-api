<?php

namespace App\Domain\Repositories;

use App\Support\TenantContext;

/**
 * Holes registered from UndSurf. They live in undsurf_holes, in the client
 * database, next to the company's own hole table, which is never written.
 */
interface HoleRegistry
{
    /**
     * @return array<string, mixed>|null null when it is not a hole registered from UndSurf
     */
    public function find(TenantContext $context, string $id, string $correlationId): ?array;

    /**
     * @param  array<string, string|null>  $fields
     * @return array<string, mixed>|null null when the hole to edit does not exist
     */
    public function save(TenantContext $context, ?string $id, array $fields, string $correlationId): ?array;
}
