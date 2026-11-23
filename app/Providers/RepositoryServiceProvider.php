<?php

namespace App\Providers;

use App\Domain\Repositories\HoleRecordsStore;
use App\Domain\Repositories\ObservationStore;
use App\Domain\Repositories\OrganizationStore;
use App\Domain\Repositories\PlanStore;
use App\Domain\Repositories\RiskStore;
use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Repositories\Gateway\GatewayHoleRecordsStore;
use App\Repositories\Gateway\GatewayObservationStore;
use App\Repositories\Gateway\GatewayOrganizationStore;
use App\Repositories\Gateway\GatewayPlanStore;
use App\Repositories\Gateway\GatewayRiskStore;
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
        $this->app->bind(HoleRecordsStore::class, GatewayHoleRecordsStore::class);
        $this->app->bind(OrganizationStore::class, GatewayOrganizationStore::class);
        $this->app->bind(ObservationStore::class, GatewayObservationStore::class);
        $this->app->bind(RiskStore::class, GatewayRiskStore::class);
        $this->app->bind(PlanStore::class, GatewayPlanStore::class);
    }
}
