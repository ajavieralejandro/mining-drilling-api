<?php

namespace App\Support;

/**
 * The canonical correlation id for this HTTP request, resolved once at the
 * edge of the tenant gateway flow (see ResolveRequestCorrelation) and
 * threaded unchanged through dispatch -> Connector -> result -> HTTP
 * response. Maps to connector_commands.correlation_id, which is
 * deliberately NOT unique in the schema — unlike request_id, the
 * Connector-protocol matching key used by ResultController, which stays
 * 100% server-generated. That is what makes it safe to accept this one
 * from an X-Correlation-Id header: a repeated client value cannot collide
 * with result delivery for a different command.
 */
final class RequestCorrelation
{
    public function __construct(public readonly string $id) {}
}
