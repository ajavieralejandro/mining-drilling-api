<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

class PermissionService
{
    /**
     * Resuelve todos los permisos activos para un usuario.
     *
     * @return array<string> Lista única de nombres de permisos
     */
    public function getPermissionsForUser(User $user, ?string $tenantId = null): array
    {
        // 1. Super Admin de plataforma tiene todos los permisos
        if ($user->isSuperAdmin()) {
            return Permission::pluck('name')->all();
        }

        // 2. Si no se pasa tenantId, resolver la membresía activa
        if ($tenantId === null) {
            $membership = $user->activeMembership();
            $tenantId = $membership?->tenant_id;
        }

        $permissions = collect();

        // 3. Permisos asignados a través de la membresía en el tenant
        if ($tenantId !== null) {
            $membership = $user->memberships()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->with(['roles.permissions'])
                ->first();

            if ($membership) {
                foreach ($membership->roles as $role) {
                    $permissions = $permissions->concat($role->permissions->pluck('name'));
                }

                // Fallback: Si no tiene roles relacionales pero tiene el string legacy de role
                if ($membership->roles->isEmpty() && $membership->role) {
                    $systemRole = Role::whereNull('tenant_id')
                        ->where('slug', $membership->role)
                        ->with('permissions')
                        ->first();

                    if ($systemRole) {
                        $permissions = $permissions->concat($systemRole->permissions->pluck('name'));
                    }
                }
            }

            // 4. Permisos heredados de grupos de usuarios en ese tenant
            $groups = $user->userGroups()
                ->where('tenant_id', $tenantId)
                ->with(['roles.permissions'])
                ->get();

            foreach ($groups as $group) {
                foreach ($group->roles as $role) {
                    $permissions = $permissions->concat($role->permissions->pluck('name'));
                }
            }
        }

        // 5. Fallback para control plane / usuarios sin tenant activo
        if ($permissions->isEmpty() && $user->role) {
            $roleSlug = is_string($user->role) ? $user->role : $user->role->value;
            $systemRole = Role::whereNull('tenant_id')
                ->where('slug', $roleSlug)
                ->with('permissions')
                ->first();

            if ($systemRole) {
                $permissions = $permissions->concat($systemRole->permissions->pluck('name'));
            }
        }

        return $permissions->unique()->values()->all();
    }

    /**
     * Determina si el usuario tiene un permiso específico.
     */
    public function hasPermission(User $user, string $permission, ?string $tenantId = null): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $permissions = $this->getPermissionsForUser($user, $tenantId);

        return in_array($permission, $permissions, true);
    }
}
