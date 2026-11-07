<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Role::with(['permissions', 'tenant:id,name'])
            ->withCount(['memberships', 'userGroups']);

        if ($request->has('tenant_id')) {
            $tenantId = $request->query('tenant_id');
            if ($tenantId === 'global' || $tenantId === null) {
                $query->whereNull('tenant_id');
            } else {
                $query->where('tenant_id', $tenantId);
            }
        }

        $roles = $query->orderBy('is_system', 'desc')->orderBy('name')->get();

        return response()->json([
            'data' => $roles,
            'total' => $roles->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'slug' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9_-]+$/',
                function ($attribute, $value, $fail) use ($request) {
                    $tenantId = $request->input('tenant_id');
                    $exists = Role::where('slug', $value)
                        ->where(function ($q) use ($tenantId) {
                            if ($tenantId) {
                                $q->where('tenant_id', $tenantId);
                            } else {
                                $q->whereNull('tenant_id');
                            }
                        })
                        ->exists();

                    if ($exists) {
                        $fail('El identificador (slug) del rol ya existe para este ámbito.');
                    }
                },
            ],
            'tenant_id' => ['nullable', 'string', 'exists:tenants,id'],
            'description' => ['nullable', 'string', 'max:255'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'tenant_id' => $validated['tenant_id'] ?? null,
            'description' => $validated['description'] ?? null,
            'is_system' => false,
        ]);

        if (! empty($validated['permission_ids'])) {
            $role->permissions()->sync($validated['permission_ids']);
        }

        $role->load(['permissions', 'tenant:id,name']);

        return response()->json([
            'message' => 'Rol creado exitosamente.',
            'data' => $role,
        ], 201);
    }

    public function show(Role $role): JsonResponse
    {
        $role->load(['permissions', 'tenant:id,name'])
            ->loadCount(['memberships', 'userGroups']);

        return response()->json([
            'data' => $role,
        ]);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:255'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        if (isset($validated['name'])) {
            $role->name = $validated['name'];
        }

        if (array_key_exists('description', $validated)) {
            $role->description = $validated['description'];
        }

        $role->save();

        if (array_key_exists('permission_ids', $validated)) {
            $role->permissions()->sync($validated['permission_ids'] ?? []);
        }

        $role->load(['permissions', 'tenant:id,name']);

        return response()->json([
            'message' => 'Rol actualizado exitosamente.',
            'data' => $role,
        ]);
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->is_system) {
            return response()->json([
                'error' => [
                    'code' => 'PROTECTED_SYSTEM_ROLE',
                    'message' => 'No es posible eliminar un rol base del sistema.',
                ],
            ], 422);
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
