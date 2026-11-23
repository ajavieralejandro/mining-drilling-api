<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Who sees and manages drilling plans and their platforms. Platforms are
 * part of a plan, so managing them needs plans.manage.
 */
class TenantPlanPolicy
{
    public function view(User $user, TenantContext $context): bool
    {
        return $this->isAdmin($user, $context)
            || $user->hasPermission('plans.view', $context->tenantId)
            || $user->hasPermission('platforms.view', $context->tenantId);
    }

    public function manage(User $user, TenantContext $context): bool
    {
        return $this->isAdmin($user, $context)
            || $user->hasPermission('plans.manage', $context->tenantId);
    }

    private function isAdmin(User $user, TenantContext $context): bool
    {
        return $user->isAdmin() || $user->isSuperAdmin() || $context->role === UserRole::Admin->value;
    }
}
