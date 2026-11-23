<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Who sees, reports and resolves the risks of the company's holes.
 */
class TenantRiskPolicy
{
    public function view(User $user, TenantContext $context): bool
    {
        return $this->isAdmin($user, $context)
            || $user->hasPermission('risks.view', $context->tenantId);
    }

    /**
     * The admin, the supervisor and whoever sees every hole report risks
     * anywhere; anyone else with the permission only on the holes whose
     * active crew they belong to.
     *
     * @param  \Closure(): list<int>  $crewUserIds
     */
    public function report(User $user, TenantContext $context, \Closure $crewUserIds): bool
    {
        if ($this->isAdmin($user, $context)) {
            return true;
        }
        if (! $user->hasPermission('risks.create', $context->tenantId)) {
            return false;
        }
        if ($context->role === UserRole::Supervisor->value
            || $user->hasPermission('drill_holes.view_all', $context->tenantId)) {
            return true;
        }

        return in_array($user->id, $crewUserIds(), true);
    }

    /** Mitigating and closing a risk. */
    public function resolve(User $user, TenantContext $context): bool
    {
        return $this->isAdmin($user, $context)
            || $user->hasPermission('risks.close', $context->tenantId);
    }

    private function isAdmin(User $user, TenantContext $context): bool
    {
        return $user->isAdmin() || $user->isSuperAdmin() || $context->role === UserRole::Admin->value;
    }
}
