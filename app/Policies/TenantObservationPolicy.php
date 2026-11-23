<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Who reads and writes the observation log of a hole and who manages the
 * company's observation types.
 */
class TenantObservationPolicy
{
    public function view(User $user, TenantContext $context): bool
    {
        return $this->isAdmin($user, $context)
            || $user->hasPermission('observations.view', $context->tenantId);
    }

    /**
     * The admin and the supervisor observe any hole; anyone else with the
     * permission only the holes whose active crew they belong to.
     *
     * @param  \Closure(): list<int>  $crewUserIds  reads the hole's active crew from
     *                                              the client database; called only
     *                                              when the answer depends on it
     */
    public function create(User $user, TenantContext $context, \Closure $crewUserIds): bool
    {
        if ($this->isAdmin($user, $context)) {
            return true;
        }
        if (! $user->hasPermission('observations.create', $context->tenantId)) {
            return false;
        }
        if ($context->role === UserRole::Supervisor->value
            || $user->hasPermission('drill_holes.view_all', $context->tenantId)) {
            return true;
        }

        return in_array($user->id, $crewUserIds(), true);
    }

    public function manageTypes(User $user, TenantContext $context): bool
    {
        return $this->isAdmin($user, $context)
            || $user->hasPermission('observations.manage_types', $context->tenantId);
    }

    private function isAdmin(User $user, TenantContext $context): bool
    {
        return $user->isAdmin() || $user->isSuperAdmin() || $context->role === UserRole::Admin->value;
    }
}
