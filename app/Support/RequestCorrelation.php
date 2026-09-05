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

    /**
     * The id bound by ResolveRequestCorrelation for this request, or null
     * when that middleware has not run (e.g. connector channel, or an
     * error that happens before the tenant group).
     */
    public static function currentId(): ?string
    {
        return app()->bound(self::class) ? app(self::class)->id : null;
    }
}
