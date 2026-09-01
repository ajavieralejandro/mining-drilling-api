<?php

namespace App\Providers;

use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Repositories\Gateway\GatewayTenantHoleRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Binds domain persistence ports to their current implementation.
 * Scope: tenant-owned data (lives in the client's own DB, reached only
 * through the Data Gateway) — see docs/sprints/distributed-data-vertical-slice.md.
 */
class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TenantHoleRepositoryInterface::class, GatewayTenantHoleRepository::class);
    }
}
