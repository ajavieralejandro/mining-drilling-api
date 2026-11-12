<?php

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

class TenantStructurePolicy
{
    public function view(User $user, TenantContext $context): bool
    {
        if ($context->role === 'admin' || $user->isAdmin() || $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission('projects.view', $context->tenantId)
            || $user->hasPermission('mines.view', $context->tenantId)
            || $user->hasPermission('plans.view', $context->tenantId)
            || $user->hasPermission('drill_holes.view_all', $context->tenantId)
            || $user->hasPermission('drill_holes.view_assigned', $context->tenantId);
    }

    public function manageProjects(User $user, TenantContext $context): bool
    {
        if ($context->role === 'admin' || $user->isAdmin() || $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission('projects.manage', $context->tenantId)
            || $user->hasPermission('plans.manage', $context->tenantId);
    }

    public function manageMines(User $user, TenantContext $context): bool
    {
        if ($context->role === 'admin' || $user->isAdmin() || $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission('mines.manage', $context->tenantId)
            || $user->hasPermission('plans.manage', $context->tenantId);
    }

    public function manageMineHoles(User $user, TenantContext $context): bool
    {
        return $this->manageMines($user, $context)
            || $user->hasPermission('drill_holes.update', $context->tenantId);
    }

    public function manageOrganization(User $user, TenantContext $context): bool
    {
        if ($context->role === 'admin' || $user->isAdmin() || $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission('tenants.manage', $context->tenantId);
    }
}
