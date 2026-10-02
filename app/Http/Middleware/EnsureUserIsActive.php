<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\GatewayException;
use App\Support\RequestCorrelation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-reads users.active from the database on every authenticated request.
 * A Sanctum token only proves that a login succeeded earlier; it does not
 * keep authorizing a user who has since been deactivated.
 *
 * Logout is intentionally not behind this middleware: a deactivated user
 * must still be able to revoke the current token. Membership is also not
 * checked here. Legacy routes authorize with the global UserRole policies,
 * and /api/auth/me only needs a currently active account.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $authenticated = $request->user();

        if (! $authenticated instanceof User) {
            return $next($request);
        }

        $user = User::query()->whereKey($authenticated->getAuthIdentifier())->first();

        if ($user === null || ! $user->active) {
            return (new GatewayException(
                'USER_INACTIVE',
                'This account is inactive.',
                401,
                null,
                RequestCorrelation::currentId(),
            ))->toResponse();
        }

        $accessToken = $authenticated->currentAccessToken();
        if ($accessToken !== null) {
            $user->withAccessToken($accessToken);
        }

        Auth::guard('sanctum')->setUser($user);
        Auth::shouldUse('sanctum');

        return $next($request);
    }
}
