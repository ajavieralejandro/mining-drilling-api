<?php

namespace App\Support;

/**
 * The server-resolved tenant identity for the current request. Built ONLY
 * by ResolveTenantContext middleware from the authenticated user's active
 * Membership — never from request body/query/headers. Controllers and
 * gateway repositories receive this instead of reading tenant_id off the
 * request, so there is no code path where a client-supplied tenant_id can
 * be trusted (docs/architecture/data-ownership.md, "Caso User"; sprint
 * decision: "nunca confiar en tenant_id enviado por el móvil").
 */
final class TenantContext
{
    public function __construct(
        public readonly string $tenantId,
        public readonly int $userId,
        public readonly string $membershipId,
        public readonly string $role,
    ) {}
}
