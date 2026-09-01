<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDemoInternal
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('connector.demo_internal_token');

        if ($expected === null || $expected === '') {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Demo endpoint not configured',
                ],
            ], 401);
        }

        $token = $request->bearerToken();

        if (! is_string($token) || ! hash_equals($expected, $token)) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Invalid demo token',
                ],
            ], 401);
        }

        return $next($request);
    }
}
