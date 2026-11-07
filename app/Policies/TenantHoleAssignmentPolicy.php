<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\TenantContext;

class TenantHoleAssignmentPolicy
{
    public function viewAny(User $user, TenantContext $context): bool
    {
        return $user->hasPermission('assignments.view', $context->tenantId)
            || $user->activeMembership()?->tenant_id === $context->tenantId;
    }

    public function manage(User $user, TenantContext $context): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin() || $context->role === UserRole::Admin->value) {
            return true;
        }

        return $user->hasPermission('assignments.manage', $context->tenantId)
            || $user->role === UserRole::Supervisor
            || $context->role === UserRole::Supervisor->value;
    }
}
