<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\TenantContext;

class TenantHoleProgressPolicy
{
    public function viewAny(User $user, TenantContext $context): bool
    {
        return $user->hasPermission('progress.view', $context->tenantId)
            || $user->activeMembership()?->tenant_id === $context->tenantId;
    }

    /**
     * @param  \Closure(): ?int  $activeDrillerId  reads the hole's active driller
     *                                             from the client database; called only
     *                                             when the answer depends on it
     */
    public function create(User $user, TenantContext $context, \Closure $activeDrillerId): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin() || $context->role === UserRole::Admin->value) {
            return true;
        }

        if ($user->hasPermission('progress.create', $context->tenantId)) {
            if ($user->hasPermission('drill_holes.view_all', $context->tenantId) || $user->role === UserRole::Supervisor || $context->role === UserRole::Supervisor->value) {
                return true;
            }

            return $activeDrillerId() === $user->id;
        }

        if ($user->role === UserRole::Supervisor || $context->role === UserRole::Supervisor->value) {
            return true;
        }

        if ($user->role === UserRole::Driller || $context->role === UserRole::Driller->value) {
            return $activeDrillerId() === $user->id;
        }

        return false;
    }

    public function initializeDepth(User $user, TenantContext $context): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin() || $context->role === UserRole::Admin->value) {
            return true;
        }

        return $user->hasPermission('progress.initialize_depth', $context->tenantId)
            || $user->role === UserRole::Supervisor
            || $context->role === UserRole::Supervisor->value;
    }

    public function review(User $user, TenantContext $context): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin() || $context->role === UserRole::Admin->value) {
            return true;
        }

        return $user->hasPermission('progress.approve', $context->tenantId)
            || $user->role === UserRole::Supervisor
            || $context->role === UserRole::Supervisor->value;
    }

    public function logCores(User $user, TenantContext $context): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin() || $context->role === UserRole::Admin->value) {
            return true;
        }

        return $user->hasPermission('geology.log_cores', $context->tenantId)
            || $user->role === UserRole::Geologist
            || $context->role === UserRole::Geologist->value;
    }
}
