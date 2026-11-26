<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreTenantHoleAssignmentRequest;
use App\Policies\TenantHoleAssignmentPolicy;
use App\Services\Tenant\HoleAccess;
use App\Services\Tenant\TenantHoleAssignmentService;
use App\Services\Tenant\TenantHoleOriginResolver;
use App\Support\GatewayException;
use App\Support\RequestCorrelation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantHoleAssignmentController extends Controller
{
    public function __construct(
        private readonly TenantHoleAssignmentService $assignmentService,
        private readonly TenantHoleOriginResolver $originResolver,
        private readonly TenantHoleAssignmentPolicy $policy,
    ) {}

    public function index(Request $request, string $hole, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->viewAny($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado']], 403);
        }
        if ($denied = app(HoleAccess::class)->denyUnlessVisible($request->user(), $context, $hole, $correlation->id)) {
            return $denied;
        }

        $requestedOrigin = $request->query('origin_id') ?: $request->header('X-Origin-Id');

        try {
            $resolved = $this->originResolver->resolveHoleAndOrigin($context, $hole, $correlation->id, $requestedOrigin);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        $holeItem = $resolved['item'];
        $crewData = $this->assignmentService->getCrewForHole($context, $hole, $correlation->id);

        return response()->json([
            'data' => [
                'hole_id' => $hole,
                'origin_id' => $crewData['origin_id'] ?? $resolved['origin_id'],
                'hole_status' => $holeItem['status'] ?? null,
                'crew' => $crewData['crew'],
                'active_count' => $crewData['active_count'],
                'assignments' => $crewData['history'],
            ],
            'request_id' => $resolved['request_id'] ?? $correlation->id,
            'correlation_id' => $correlation->id,
        ]);
    }

    public function store(StoreTenantHoleAssignmentRequest $request, string $hole, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manage($request->user(), $context)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Solo administradores y supervisores pueden gestionar la cuadrilla de este pozo.',
                ],
            ], 403);
        }

        $requestedOrigin = $request->input('origin_id') ?: $request->header('X-Origin-Id');

        try {
            $resolved = $this->originResolver->resolveHoleAndOrigin($context, $hole, $correlation->id, $requestedOrigin);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        $updatedCrew = $this->assignmentService->assignCrew($context, $hole, $request->validated(), $correlation->id);

        return response()->json([
            'message' => 'Cuadrilla actualizada exitosamente.',
            'data' => [
                'hole_id' => $hole,
                'origin_id' => $updatedCrew['origin_id'] ?? $resolved['origin_id'],
                'crew' => $updatedCrew['crew'],
                'active_count' => $updatedCrew['active_count'],
            ],
            'request_id' => $resolved['request_id'] ?? $correlation->id,
            'correlation_id' => $correlation->id,
        ], 201);
    }

    public function eligiblePersonnel(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->viewAny($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado']], 403);
        }

        $personnel = $this->assignmentService->getEligiblePersonnel($context->tenantId);

        return response()->json([
            'data' => $personnel,
            'request_id' => $correlation->id,
            'correlation_id' => $correlation->id,
        ]);
    }
}
