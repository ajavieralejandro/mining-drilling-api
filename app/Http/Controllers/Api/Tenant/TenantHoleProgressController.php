<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\InitializeHoleDepthRequest;
use App\Http\Requests\Tenant\StoreTenantHoleProgressRequest;
use App\Http\Resources\Tenant\TenantHoleProgressLogResource;
use App\Policies\TenantHoleProgressPolicy;
use App\Services\Tenant\TenantHoleOperationalStateService;
use App\Services\Tenant\TenantHoleOriginResolver;
use App\Services\Tenant\TenantHoleProgressService;
use App\Support\GatewayException;
use App\Support\RequestCorrelation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantHoleProgressController extends Controller
{
    public function __construct(
        private readonly TenantHoleProgressService $progressService,
        private readonly TenantHoleOperationalStateService $operationalService,
        private readonly TenantHoleOriginResolver $originResolver,
        private readonly TenantHoleProgressPolicy $policy,
    ) {}

    public function index(Request $request, string $hole, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->viewAny($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado']], 403);
        }

        $requestedOrigin = $request->query('origin_id') ?: $request->header('X-Origin-Id');

        try {
            $resolved = $this->originResolver->resolveHoleAndOrigin($context, $hole, $correlation->id, $requestedOrigin);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        $originId = $resolved['origin_id'];

        $perPage = (int) $request->query('per_page', 15);
        $perPage = ($perPage > 0 && $perPage <= 100) ? $perPage : 15;
        $page = max(1, (int) $request->query('page', 1));

        $paginator = $this->progressService->listProgress($context->tenantId, $originId, $hole, $perPage, $page);
        $summary = $this->operationalService->getSummary($context->tenantId, $originId, $hole);

        return response()->json([
            'data' => TenantHoleProgressLogResource::collection($paginator->items()),
            'summary' => $summary,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'request_id' => $resolved['request_id'] ?? $correlation->id,
            'correlation_id' => $correlation->id,
        ]);
    }

    public function store(StoreTenantHoleProgressRequest $request, string $hole, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        $requestedOrigin = $request->input('origin_id') ?: $request->header('X-Origin-Id');

        try {
            $resolved = $this->originResolver->resolveHoleAndOrigin($context, $hole, $correlation->id, $requestedOrigin);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        $originId = $resolved['origin_id'];
        $holeItem = $resolved['item'];

        // Autorizar ANTES de procesar o devolver cualquier replay idempotente
        if (! $this->policy->create($request->user(), $context, $hole, $originId)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Solo los perforistas asignados activamente a este pozo o supervisores pueden registrar avances.',
                ],
            ], 403);
        }

        $result = $this->progressService->recordProgress(
            $context,
            $originId,
            $hole,
            $request->validated(),
            $holeItem,
        );

        $isReplay = $result['idempotent_replay'];
        $statusCode = $isReplay ? 200 : 201;

        $response = response()->json([
            'message' => $isReplay
                ? 'Avance ya registrado previamente (respuesta idempotente).'
                : 'Avance registrado exitosamente.',
            'data' => new TenantHoleProgressLogResource($result['progress']),
            'idempotent_replay' => $isReplay,
            'request_id' => $resolved['request_id'] ?? $correlation->id,
            'correlation_id' => $correlation->id,
        ], $statusCode);

        if ($isReplay) {
            $response->headers->set('X-Idempotent-Replay', 'true');
        }

        return $response;
    }

    public function initializeDepth(InitializeHoleDepthRequest $request, string $hole, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->initializeDepth($request->user(), $context)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Solo administradores y supervisores pueden inicializar la profundidad de este pozo.',
                ],
            ], 403);
        }

        $requestedOrigin = $request->input('origin_id') ?: $request->header('X-Origin-Id');

        try {
            $resolved = $this->originResolver->resolveHoleAndOrigin($context, $hole, $correlation->id, $requestedOrigin);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        $originId = $resolved['origin_id'];
        $holeItem = $resolved['item'];

        $this->operationalService->initializeDepth(
            $context,
            $originId,
            $hole,
            $request->input('initial_depth'),
            $request->input('notes'),
            $holeItem,
        );

        $summary = $this->operationalService->getSummary($context->tenantId, $originId, $hole);

        return response()->json([
            'message' => 'Profundidad inicial del pozo establecida exitosamente.',
            'data' => $summary,
            'request_id' => $resolved['request_id'] ?? $correlation->id,
            'correlation_id' => $correlation->id,
        ]);
    }
}
