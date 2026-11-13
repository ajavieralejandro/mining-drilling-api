<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Policies\TenantRolePolicy;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantRoleController extends Controller
{
    public function __construct(
        private readonly TenantRolePolicy $policy,
    ) {}

    public function permissions(Request $request, TenantContext $context): JsonResponse
    {
        if (! $this->policy->viewAny($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado']], 403);
        }

        $permissions = Permission::orderBy('module')->orderBy('name')->get();

        return response()->json([
            'data' => $permissions,
            'modules' => $permissions->groupBy('module'),
            'total' => $permissions->count(),
        ]);
    }

    public function index(Request $request, TenantContext $context): JsonResponse
    {
        if (! $this->policy->viewAny($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado']], 403);
        }

        $query = Role::with(['permissions:id,name,module,description', 'tenant:id,name'])
            ->withCount(['memberships', 'userGroups'])
            ->where(function ($q) use ($context) {
                $q->whereNull('tenant_id')
                    ->orWhere('tenant_id', $context->tenantId);
            });

        if ($request->has('type')) {
            $type = $request->query('type');
            if ($type === 'system') {
                $query->whereNull('tenant_id');
            } elseif ($type === 'custom') {
                $query->where('tenant_id', $context->tenantId);
            }
        }

        $roles = $query->orderBy('is_system', 'desc')->orderBy('name')->get();

        return response()->json([
            'data' => $roles,
            'total' => $roles->count(),
        ]);
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        if (! $this->policy->manage($request->user(), $context)) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Solo administradores pueden crear roles'],
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'slug' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9_-]+$/',
                function ($attribute, $value, $fail) use ($context) {
                    $systemExists = Role::whereNull('tenant_id')->where('slug', $value)->exists();
                    if ($systemExists) {
                        $fail('El identificador (slug) coincide con un rol base del sistema.');

                        return;
                    }

                    $tenantExists = Role::where('tenant_id', $context->tenantId)->where('slug', $value)->exists();
                    if ($tenantExists) {
                        $fail('El identificador (slug) del rol ya existe para esta organización.');
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role = Role::create([
            'name' => trim($validated['name']),
            'slug' => strtolower(trim($validated['slug'])),
            'tenant_id' => $context->tenantId,
            'description' => isset($validated['description']) ? trim($validated['description']) : null,
            'is_system' => false,
        ]);

        if (! empty($validated['permission_ids'])) {
            $role->permissions()->sync($validated['permission_ids']);
        }

        $role->load(['permissions:id,name,module,description', 'tenant:id,name'])
            ->loadCount(['memberships', 'userGroups']);

        return response()->json([
            'message' => 'Rol creado exitosamente.',
            'data' => $role,
        ], 201);
    }

    public function show(string $roleId, Request $request, TenantContext $context): JsonResponse
    {
        if (! $this->policy->viewAny($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado']], 403);
        }

        $role = Role::where(function ($q) use ($context) {
            $q->whereNull('tenant_id')->orWhere('tenant_id', $context->tenantId);
        })
            ->with(['permissions:id,name,module,description', 'tenant:id,name'])
            ->withCount(['memberships', 'userGroups'])
            ->find($roleId);

        if (! $role) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Rol no encontrado.']], 404);
        }

        return response()->json([
            'data' => $role,
        ]);
    }

    public function update(Request $request, string $roleId, TenantContext $context): JsonResponse
    {
        if (! $this->policy->manage($request->user(), $context)) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Solo administradores pueden modificar roles'],
            ], 403);
        }

        $role = Role::find($roleId);

        if (! $role) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Rol no encontrado.']], 404);
        }

        if ($role->is_system || $role->tenant_id === null) {
            return response()->json([
                'error' => [
                    'code' => 'PROTECTED_SYSTEM_ROLE',
                    'message' => 'No es posible modificar un rol base del sistema.',
                ],
            ], 422);
        }

        if ($role->tenant_id !== $context->tenantId) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Rol no encontrado.']], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:255'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        if (isset($validated['name'])) {
            $role->name = trim($validated['name']);
        }
        if (array_key_exists('description', $validated)) {
            $role->description = $validated['description'] !== null ? trim($validated['description']) : null;
        }

        $role->save();

        if (array_key_exists('permission_ids', $validated)) {
            $role->permissions()->sync($validated['permission_ids'] ?? []);
        }

        $role->load(['permissions:id,name,module,description', 'tenant:id,name'])
            ->loadCount(['memberships', 'userGroups']);

        return response()->json([
            'message' => 'Rol actualizado exitosamente.',
            'data' => $role,
        ]);
    }

    public function destroy(string $roleId, Request $request, TenantContext $context): JsonResponse
    {
        if (! $this->policy->manage($request->user(), $context)) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Solo administradores pueden eliminar roles'],
            ], 403);
        }

        $role = Role::find($roleId);

        if (! $role) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Rol no encontrado.']], 404);
        }

        if ($role->is_system || $role->tenant_id === null) {
            return response()->json([
                'error' => [
                    'code' => 'PROTECTED_SYSTEM_ROLE',
                    'message' => 'No es posible eliminar un rol base del sistema.',
                ],
            ], 422);
        }

        if ($role->tenant_id !== $context->tenantId) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Rol no encontrado.']], 404);
        }

        $role->permissions()->detach();
        $role->memberships()->detach();
        $role->userGroups()->detach();
        $role->delete();

        return response()->json([
            'message' => 'Rol eliminado exitosamente.',
        ]);
    }
}
