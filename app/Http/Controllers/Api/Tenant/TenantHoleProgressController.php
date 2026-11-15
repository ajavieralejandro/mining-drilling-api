<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\InitializeHoleDepthRequest;
use App\Http\Requests\Tenant\ReviewTenantHoleProgressRequest;
use App\Http\Requests\Tenant\StoreTenantCoreLogRequest;
use App\Http\Requests\Tenant\StoreTenantHoleProgressRequest;
use App\Http\Requests\Tenant\UpdateTenantCoreLogRequest;
use App\Http\Resources\Tenant\TenantCoreLogResource;
use App\Http\Resources\Tenant\TenantHoleProgressLogResource;
use App\Models\TenantCoreLog;
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

        $status = $request->query('status');
        $paginator = $this->progressService->listProgress($context->tenantId, $originId, $hole, $perPage, $page, $status);
        $summary = $this->operationalService->getSummary($context->tenantId, $originId, $hole, $resolved['item']['status'] ?? null);

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

    public function show(Request $request, string $id, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->viewAny($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado']], 403);
        }

        $log = $this->progressService->getProgress($context->tenantId, $id);

        return response()->json([
            'data' => new TenantHoleProgressLogResource($log),
            'correlation_id' => $correlation->id,
        ]);
    }

    public function pending(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->review($request->user(), $context)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Solo supervisores y administradores pueden acceder a la bandeja de partes pendientes.',
                ],
            ], 403);
        }

        $originId = $request->query('origin_id');
        $holeId = $request->query('hole_id');
        $perPage = (int) $request->query('per_page', 15);
        $perPage = ($perPage > 0 && $perPage <= 100) ? $perPage : 15;
        $page = max(1, (int) $request->query('page', 1));

        $paginator = $this->progressService->listPendingProgress($context->tenantId, $originId, $holeId, $perPage, $page);
        $summary = $this->progressService->getPendingSummary($context->tenantId, $originId, $holeId);

        return response()->json([
            'data' => TenantHoleProgressLogResource::collection($paginator->items()),
            'summary' => $summary,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'correlation_id' => $correlation->id,
        ]);
    }

    public function review(ReviewTenantHoleProgressRequest $request, string $id, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->review($request->user(), $context)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Solo supervisores y administradores pueden aprobar o rechazar partes de avance.',
                ],
            ], 403);
        }

        $validated = $request->validated();
        $updated = $this->progressService->reviewProgress(
            $context->tenantId,
            $id,
            $validated['action'],
            $validated['review_notes'] ?? null,
            $context->userId
        );

        $statusEs = $updated->status === 'approved' ? 'aprobado' : 'rechazado';

        return response()->json([
            'message' => "Parte diario {$statusEs} exitosamente.",
            'data' => new TenantHoleProgressLogResource($updated),
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
                : 'Avance registrado exitosamente en estado pendiente de revisión por el supervisor.',
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

    public function coreLogsIndex(Request $request, string $hole, TenantContext $context, RequestCorrelation $correlation): JsonResponse
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

        $perPage = (int) $request->query('per_page', 25);
        $perPage = ($perPage > 0 && $perPage <= 100) ? $perPage : 25;
        $page = max(1, (int) $request->query('page', 1));

        $paginator = $this->progressService->listCoreLogs($context->tenantId, $originId, $hole, $perPage, $page);
        $rqdSummary = TenantCoreLog::calculateHoleRqdSummary($context->tenantId, $originId, $hole);

        return response()->json([
            'data' => TenantCoreLogResource::collection($paginator->items()),
            'rqd_summary' => $rqdSummary,
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

    public function coreLogsShow(Request $request, string $hole, string $id, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->viewAny($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado']], 403);
        }

        $coreLog = $this->progressService->getCoreLog($context->tenantId, $id);

        return response()->json([
            'data' => new TenantCoreLogResource($coreLog),
            'correlation_id' => $correlation->id,
        ]);
    }

    public function coreLogsUpdate(UpdateTenantCoreLogRequest $request, string $hole, string $id, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->logCores($request->user(), $context)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Solo geólogos y administradores pueden modificar tramos en loguera.',
                ],
            ], 403);
        }

        $updated = $this->progressService->updateCoreLog($context->tenantId, $id, $request->validated());

        return response()->json([
            'message' => 'Tramo de testigo y cálculo de RQD actualizados exitosamente.',
            'data' => new TenantCoreLogResource($updated),
            'correlation_id' => $correlation->id,
        ]);
    }

    public function coreLogsDestroy(Request $request, string $hole, string $id, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->logCores($request->user(), $context)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Solo geólogos y administradores pueden eliminar tramos en loguera.',
                ],
            ], 403);
        }

        $this->progressService->deleteCoreLog($context->tenantId, $id);

        return response()->json([
            'message' => 'Tramo de testigo eliminado exitosamente.',
            'correlation_id' => $correlation->id,
        ]);
    }

    public function coreLogsStore(StoreTenantCoreLogRequest $request, string $hole, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        $requestedOrigin = $request->input('origin_id') ?: $request->header('X-Origin-Id');

        try {
            $resolved = $this->originResolver->resolveHoleAndOrigin($context, $hole, $correlation->id, $requestedOrigin);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        $originId = $resolved['origin_id'];

        if (! $this->policy->logCores($request->user(), $context)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Solo geólogos y administradores pueden asentar corridas y calcular RQD en loguera.',
                ],
            ], 403);
        }

        $coreLog = $this->progressService->recordCoreLog(
            $context,
            $originId,
            $hole,
            $request->validated()
        );

        return response()->json([
            'message' => 'Tramo de testigo y cálculo de RQD asentados exitosamente.',
            'data' => new TenantCoreLogResource($coreLog),
            'request_id' => $resolved['request_id'] ?? $correlation->id,
            'correlation_id' => $correlation->id,
        ], 201);
    }
}
