<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for this request from the authenticated user's active
 * membership and nothing else. Runs after auth:sanctum. Any route behind
 * this middleware may type-hint TenantContext in its controller method and
 * the container will resolve the instance bound here.
 */
class ResolveTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        /** @var Membership|null $membership */
        $membership = $user?->activeMembership();

        if ($membership === null) {
            return response()->json([
                'error' => [
                    'code' => 'NO_ACTIVE_MEMBERSHIP',
                    'message' => 'This user has no active tenant membership',
                ],
            ], 403);
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
