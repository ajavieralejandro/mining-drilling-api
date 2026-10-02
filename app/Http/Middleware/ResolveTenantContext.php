<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Support\GatewayException;
use App\Support\RequestCorrelation;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for this request from the authenticated user's active
 * membership and nothing else. The membership row is read from the database
 * on every request; a token issued while the membership was active does not
 * keep authorizing it after the row leaves status=active.
 *
 * The tenant is never taken from the request. A membership for another
 * tenant does not satisfy this check, and client-supplied tenant ids are
 * ignored. Runs after auth:sanctum and EnsureUserIsActive, and before any
 * controller can query tenant data or dispatch a Connector command.
 */
class ResolveTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        /** @var Membership|null $membership */
        $membership = $user?->activeMembership();

        if ($membership === null) {
            return (new GatewayException(
                'NO_ACTIVE_MEMBERSHIP',
                'This user has no active tenant membership',
                403,
                null,
                RequestCorrelation::currentId(),
            ))->toResponse();
        }

        app()->instance(TenantContext::class, new TenantContext(
            tenantId: $membership->tenant_id,
            userId: $user->id,
            membershipId: $membership->id,
            role: $membership->role,
        ));

        return $next($request);
    }
}
