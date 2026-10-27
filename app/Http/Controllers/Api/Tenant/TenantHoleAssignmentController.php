<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreTenantHoleAssignmentRequest;
use App\Http\Resources\Tenant\TenantHoleAssignmentResource;
use App\Policies\TenantHoleAssignmentPolicy;
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

        $requestedOrigin = $request->query('origin_id') ?: $request->header('X-Origin-Id');

        try {
            $originId = $this->originResolver->resolveAuthorizedOrigin($context, $hole, $requestedOrigin, $correlation->id);
            $this->originResolver->verifyHoleInGateway($context, $correlation->id, $hole, $originId);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        $crewData = $this->assignmentService->getCrewForHole($context->tenantId, $originId, $hole);
        $history = $this->assignmentService->listAssignments($context->tenantId, $originId, $hole);

        return response()->json([
            'data' => [
                'hole_id' => $hole,
                'origin_id' => $originId,
                'crew' => $crewData['crew'],
                'active_count' => $crewData['active_count'],
                'assignments' => TenantHoleAssignmentResource::collection($history),
            ],
            'request_id' => $correlation->id,
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
            $originId = $this->originResolver->resolveAuthorizedOrigin($context, $hole, $requestedOrigin, $correlation->id);
            $this->originResolver->verifyHoleInGateway($context, $correlation->id, $hole, $originId);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        $updatedCrew = $this->assignmentService->assignCrew($context, $originId, $hole, $request->validated());

        return response()->json([
            'message' => 'Cuadrilla actualizada exitosamente.',
            'data' => [
                'hole_id' => $hole,
                'origin_id' => $originId,
                'crew' => $updatedCrew['crew'],
                'active_count' => $updatedCrew['active_count'],
            ],
            'request_id' => $correlation->id,
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
