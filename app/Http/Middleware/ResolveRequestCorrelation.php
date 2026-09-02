<?php

namespace App\Http\Middleware;

use App\Support\RequestCorrelation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the canonical correlation id for this request as early as
 * possible — before any Connector dispatch — so a failure that happens
 * before a ConnectorCommand even exists (e.g. CONNECTOR_OFFLINE) still has
 * an id to report, instead of losing correlation until the first command
 * row is created (which is what CommandDispatcher::dispatch() used to do).
 *
 * An X-Correlation-Id sent by the client is honored if it is a bounded,
 * safe opaque token. It carries no authority (unlike tenant_id — see
 * TenantContext, which is never built from client input) and maps to the
 * non-unique correlation_id column, so a repeated or malicious value
 * cannot collide with anything or grant access — worst case it just makes
 * two unrelated log entries share a label.
 */
class ResolveRequestCorrelation
{
    private const MAX_LENGTH = 64;

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header('X-Correlation-Id');

        $id = is_string($incoming) && $this->isValid($incoming)
            ? $incoming
            : 'cor_'.(string) Str::ulid();

        app()->instance(RequestCorrelation::class, new RequestCorrelation($id));

        return $next($request);
    }

    private function isValid(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= self::MAX_LENGTH
            && preg_match('/^[\x21-\x7E]+$/', $value) === 1;
    }
}
