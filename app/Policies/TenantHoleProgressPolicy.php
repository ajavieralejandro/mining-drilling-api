<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\TenantContext;

class TenantHoleProgressPolicy
{
    public function viewAny(User $user, TenantContext $context): bool
    {
        return $user->activeMembership()?->tenant_id === $context->tenantId;
    }

    public function create(User $user, TenantContext $context, string $holeId, ?string $originId = null): bool
    {
        if ($user->isAdmin() || $context->role === UserRole::Admin->value) {
            return true;
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
        if ($user->isAdmin() || $context->role === UserRole::Admin->value) {
            return true;
        }

        return $user->role === UserRole::Supervisor || $context->role === UserRole::Supervisor->value;
    }
}
