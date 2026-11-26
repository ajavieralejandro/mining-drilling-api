<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\TenantContext;

class TenantHoleProgressPolicy
{
    public function viewAny(User $user, TenantContext $context): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin() || $context->role === UserRole::Admin->value) {
            return true;
        }

        return $user->hasPermission('progress.view', $context->tenantId);
    }

    /**
     * Whoever sees every hole records progress on any of them; anyone else with
     * the permission only on the hole where they are the active driller.
     *
     * @param  \Closure(): ?int  $activeDrillerId  reads the hole's active driller
     *                                             from the client database; called only
     *                                             when the answer depends on it
     */
    public function create(User $user, TenantContext $context, \Closure $activeDrillerId): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin() || $context->role === UserRole::Admin->value) {
            return true;
        }

        if (! $user->hasPermission('progress.create', $context->tenantId)) {
            return false;
        }
        if ($user->hasPermission('drill_holes.view_all', $context->tenantId)) {
            return true;
        }

        return $activeDrillerId() === $user->id;
    }

    public function initializeDepth(User $user, TenantContext $context): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin() || $context->role === UserRole::Admin->value) {
            return true;
        }

        return $user->hasPermission('progress.initialize_depth', $context->tenantId);
    }

    public function review(User $user, TenantContext $context): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin() || $context->role === UserRole::Admin->value) {
            return true;
        }

        return $user->hasPermission('progress.approve', $context->tenantId);
    }

    public function logCores(User $user, TenantContext $context): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin() || $context->role === UserRole::Admin->value) {
            return true;
        }

        return $user->hasPermission('geology.log_cores', $context->tenantId);
    }
}
