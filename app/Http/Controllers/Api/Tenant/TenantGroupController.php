<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\UserGroup;
use App\Policies\TenantGroupPolicy;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantGroupController extends Controller
{
    public function __construct(
        private readonly TenantGroupPolicy $policy,
    ) {}

    public function index(Request $request, TenantContext $context): JsonResponse
    {
        if (! $this->policy->viewAny($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado']], 403);
        }

        $groups = UserGroup::where('tenant_id', $context->tenantId)
            ->with(['roles:id,name,slug', 'users:id,name,email'])
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $groups,
            'total' => $groups->count(),
        ]);
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        if (! $this->policy->manage($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'No autorizado para gestionar grupos']], 403);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:128',
                Rule::unique('user_groups', 'name')->where('tenant_id', $context->tenantId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $group = UserGroup::create([
            'tenant_id' => $context->tenantId,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        if (! empty($validated['user_ids'])) {
            $group->users()->sync($validated['user_ids']);
        }

        if (! empty($validated['role_ids'])) {
            $group->roles()->sync($validated['role_ids']);
        }

        $group->load(['roles:id,name,slug', 'users:id,name,email']);

        return response()->json([
            'message' => 'Grupo creado exitosamente.',
            'data' => $group,
        ], 201);
    }

    public function show(Request $request, int $id, TenantContext $context): JsonResponse
    {
        if (! $this->policy->viewAny($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado']], 403);
        }

        $group = UserGroup::where('tenant_id', $context->tenantId)
            ->with(['roles:id,name,slug,description', 'users:id,name,email'])
            ->findOrFail($id);

        return response()->json([
            'data' => $group,
        ]);
    }

    public function update(Request $request, int $id, TenantContext $context): JsonResponse
    {
        if (! $this->policy->manage($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'No autorizado para gestionar grupos']], 403);
        }

        $group = UserGroup::where('tenant_id', $context->tenantId)->findOrFail($id);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:128',
                Rule::unique('user_groups', 'name')->where('tenant_id', $context->tenantId)->ignore($group->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        if (isset($validated['name'])) {
            $group->name = $validated['name'];
        }

        if (array_key_exists('description', $validated)) {
            $group->description = $validated['description'];
        }

        $group->save();

        if (array_key_exists('user_ids', $validated)) {
            $group->users()->sync($validated['user_ids'] ?? []);
        }

        if (array_key_exists('role_ids', $validated)) {
            $group->roles()->sync($validated['role_ids'] ?? []);
        }

        $group->load(['roles:id,name,slug', 'users:id,name,email']);

        return response()->json([
            'message' => 'Grupo actualizado exitosamente.',
            'data' => $group,
        ]);
    }

    public function destroy(Request $request, int $id, TenantContext $context): JsonResponse
    {
        if (! $this->policy->manage($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'No autorizado para gestionar grupos']], 403);
        }

        $group = UserGroup::where('tenant_id', $context->tenantId)->findOrFail($id);
        $group->users()->detach();
        $group->roles()->detach();
        $group->delete();

        return response()->json([
            'message' => 'Grupo eliminado exitosamente.',
        ]);
    }
}
