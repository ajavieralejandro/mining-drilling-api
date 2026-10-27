<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\TenantContext;

class TenantHoleAssignmentPolicy
{
    public function viewAny(User $user, TenantContext $context): bool
    {
        return $user->activeMembership()?->tenant_id === $context->tenantId;
    }

    public function manage(User $user, TenantContext $context): bool
    {
        if ($user->isAdmin() || $context->role === UserRole::Admin->value) {
            return true;
        }

        return $user->role === UserRole::Supervisor || $context->role === UserRole::Supervisor->value;
    }
}
