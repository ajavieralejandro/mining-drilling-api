<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Policies\TenantStructurePolicy;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantOrganizationController extends Controller
{
    public function __construct(
        private readonly TenantStructurePolicy $policy,
    ) {}

    public function show(Request $request, TenantContext $context): JsonResponse
    {
        if (! $this->policy->view($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado.']], 403);
        }

        $tenant = Tenant::query()
            ->withCount(['projects', 'mines'])
            ->findOrFail($context->tenantId);

        return response()->json([
            'data' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'projects_count' => $tenant->projects_count,
                'mines_count' => $tenant->mines_count,
                'created_at' => $tenant->created_at?->toIso8601String(),
                'updated_at' => $tenant->updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function update(Request $request, TenantContext $context): JsonResponse
    {
        if (! $this->policy->manageOrganization($request->user(), $context)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'No autorizado para modificar la organización.',
                ],
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
        ], [
            'name.required' => 'El nombre de la organización es obligatorio.',
            'name.min' => 'El nombre de la organización debe tener al menos 2 caracteres.',
        ]);

        $tenant = Tenant::query()->findOrFail($context->tenantId);
        $tenant->name = trim($validated['name']);
        $tenant->save();

        return response()->json([
            'message' => 'Nombre de la organización actualizado correctamente.',
            'data' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'updated_at' => $tenant->updated_at?->toIso8601String(),
            ],
        ]);
    }
}
