<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public site calls the API with a Bearer token. Sanctum's stateful
 * mode would turn that same Origin into a cookie session and reject the
 * login with a CSRF 419. Cookie sessions on other configured domains, and
 * the web middleware group, keep their CSRF checks.
 */
class ExcludePublicSiteFromSanctumState
{
    /** @var list<string> */
    private const PUBLIC_HOSTS = [
        'undsurf.com',
        'www.undsurf.com',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $stateful = config('sanctum.stateful', []);

        config([
            'sanctum.stateful' => array_values(array_filter(
                is_array($stateful) ? $stateful : [],
                fn ($domain) => ! in_array($this->host((string) $domain), self::PUBLIC_HOSTS, true),
            )),
        ]);

        return $next($request);
    }

    private function host(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain, 2)[0];

        return explode(':', $domain, 2)[0];
    }
}
