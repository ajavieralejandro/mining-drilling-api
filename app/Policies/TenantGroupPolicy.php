<?php

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

class TenantGroupPolicy
{
    public function viewAny(User $user, TenantContext $context): bool
    {
        return $user->hasPermission('groups.view', $context->tenantId)
            || $user->hasPermission('personnel.view', $context->tenantId)
            || $user->activeMembership()?->tenant_id === $context->tenantId;
    }

    public function manage(User $user, TenantContext $context): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission('groups.manage', $context->tenantId)
            || $user->hasPermission('personnel.manage', $context->tenantId);
    }
}
