<?php

namespace App\Http\Middleware;

use App\Models\Connector;
use App\Support\TokenHasher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateConnector
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->bearerToken();

        if ($header === null || $header === '') {
            return response()->json([
                'protocol_version' => config('connector.protocol_version'),
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Missing connector bearer token',
                ],
            ], 401);
        }

        $connector = Connector::query()
            ->where('connector_token_hash', TokenHasher::hash($header))
            ->first();

        if ($connector === null || $connector->status === Connector::STATUS_REVOKED) {
            return response()->json([
                'protocol_version' => config('connector.protocol_version'),
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Invalid connector credentials',
                ],
            ], 401);
        }

        $request->attributes->set('connector', $connector);
        $request->attributes->set('connector_token_plaintext_present', true);

        return $next($request);
    }
}
