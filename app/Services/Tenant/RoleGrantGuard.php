<?php

namespace App\Services\Tenant;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Who may hand out which role (to a person or to a group of users).
 *
 * - Only a system role or a role of the same organization.
 * - `super_admin` only from a platform super admin.
 * - Anyone else who is not the organization's admin may not grant a role
 *   that carries administration permissions they lack (ADMINISTRATION):
 *   a supervisor registers drillers or geologists, but cannot create an
 *   admin or put themselves in a group with admin access.
 */
class RoleGrantGuard
{
    /**
     * @param  list<int>  $roleIds
     *
     * @throws ValidationException
     */
    public function assertCanGrantIds(User $actor, TenantContext $context, array $roleIds, string $field): void
    {
        if ($roleIds === []) {
            return;
        }
        $roles = Role::query()->whereIn('id', $roleIds)->with('permissions:id,name')->get();
        $denied = $roles->reject(fn (Role $role) => $this->canGrant($actor, $context, $role))->pluck('slug');
        $unknown = count(array_unique($roleIds)) - $roles->count();
        $foreign = $roles->filter(fn (Role $role) => $role->tenant_id !== null && $role->tenant_id !== $context->tenantId)->count();

        if ($denied->isNotEmpty() || $unknown > 0 || $foreign > 0) {
            throw ValidationException::withMessages([
                $field => ['No podés asignar estos roles: '.($denied->isNotEmpty() ? $denied->implode(', ') : 'no pertenecen a esta organización').'.'],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function assertCanGrantSlug(User $actor, TenantContext $context, string $slug, string $field): void
    {
        $role = Role::forTenant($context->tenantId)
            ->where('slug', $slug)
            ->orderByRaw('tenant_id is null') // the organization's own role first
            ->with('permissions:id,name')
            ->first();

        if ($role === null && UserRole::tryFrom($slug) !== null && $this->isOrganizationAdmin($actor, $context)) {
            // A system role not seeded yet: only the admin may hand it out.
            return;
        }
        if ($role === null || ! $this->canGrant($actor, $context, $role)) {
            throw ValidationException::withMessages([
                $field => ['No podés asignar el rol '.$slug.': incluye permisos de administración que tu rol no tiene.'],
            ]);
        }
    }

    /**
     * Group members must belong to the organization (active membership).
     *
     * @param  list<int>  $userIds
     *
     * @throws ValidationException
     */
    public function assertMembers(TenantContext $context, array $userIds, string $field): void
    {
        $userIds = array_values(array_unique($userIds));
        if ($userIds === []) {
            return;
        }
        $members = Membership::query()
            ->where('tenant_id', $context->tenantId)
            ->where('status', Membership::STATUS_ACTIVE)
            ->whereIn('user_id', $userIds)
            ->distinct()
            ->count('user_id');

        if ($members !== count($userIds)) {
            throw ValidationException::withMessages([
                $field => ['Solo se pueden agregar integrantes activos de esta organización.'],
            ]);
        }
    }

    public function canGrant(User $actor, TenantContext $context, Role $role): bool
    {
        if ($role->tenant_id !== null && $role->tenant_id !== $context->tenantId) {
            return false;
        }
        if ($role->slug === 'super_admin' && $role->tenant_id === null) {
            return $actor->isSuperAdmin();
        }
        if ($this->isOrganizationAdmin($actor, $context)) {
            return true;
        }

        // Operational roles (driller, helper, geologist...) stay assignable by the
        // supervisor; what no one hands out without holding it is administration.
        $mine = $actor->permissions($context->tenantId);

        return $role->permissions->pluck('name')
            ->intersect(self::ADMINISTRATION)
            ->diff($mine)
            ->isEmpty();
    }

    /** Permissions that open the organization's administration. */
    public const ADMINISTRATION = [
        'roles.manage',
        'users.manage',
        'tenants.manage',
        'source_config.view',
        'source_config.manage',
        'audit.view',
    ];

    private function isOrganizationAdmin(User $actor, TenantContext $context): bool
    {
        return $actor->isSuperAdmin() || $actor->isAdmin() || $context->role === UserRole::Admin->value;
    }
}
