<?php

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

class TenantRolePolicy
{
    public function viewAny(User $user, TenantContext $context): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission('roles.view', $context->tenantId)
            || $user->hasPermission('roles.manage', $context->tenantId)
            || $user->hasPermission('personnel.view', $context->tenantId);
    }

    public function manage(User $user, TenantContext $context): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission('roles.manage', $context->tenantId);
    }
}
