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

    public function create(User $user, TenantContext $context, string $holeId, ?string $originId = null): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin() || $context->role === UserRole::Admin->value) {
            return true;
        }

        if ($user->hasPermission('progress.create', $context->tenantId)) {
            if ($user->hasPermission('drill_holes.view_all', $context->tenantId) || $user->role === UserRole::Supervisor || $context->role === UserRole::Supervisor->value) {
                return true;
            }

            return $user->isAssignedAsDrillerToTenantHole($context->tenantId, $holeId, $originId);
        }

        if ($user->role === UserRole::Supervisor || $context->role === UserRole::Supervisor->value) {
            return true;
        }

        if ($user->role === UserRole::Driller || $context->role === UserRole::Driller->value) {
            return $user->isAssignedAsDrillerToTenantHole($context->tenantId, $holeId, $originId);
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
}
