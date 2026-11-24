<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\TenantContext;

/** Who registers and edits the holes registered from UndSurf. */
class TenantHolePolicy
{
    public function create(User $user, TenantContext $context): bool
    {
        return $this->allows($user, $context, 'drill_holes.create');
    }

    public function update(User $user, TenantContext $context): bool
    {
        return $this->allows($user, $context, 'drill_holes.update');
    }

    private function allows(User $user, TenantContext $context, string $permission): bool
    {
        return $user->isAdmin()
            || $user->isSuperAdmin()
            || $context->role === UserRole::Admin->value
            || $user->hasPermission($permission, $context->tenantId);
    }
}
