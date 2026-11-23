<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\InitializeHoleDepthRequest;
use App\Http\Requests\Tenant\ReviewTenantHoleProgressRequest;
use App\Http\Requests\Tenant\StoreTenantCoreLogRequest;
use App\Http\Requests\Tenant\StoreTenantHoleProgressRequest;
use App\Http\Requests\Tenant\UpdateTenantCoreLogRequest;
use App\Http\Resources\Tenant\CoreLogPresenter;
use App\Http\Resources\Tenant\ProgressReportPresenter;
use App\Policies\TenantHoleProgressPolicy;
use App\Services\Tenant\TenantHoleAssignmentService;
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
        private readonly TenantHoleAssignmentService $assignmentService,
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

        $perPage = (int) $request->query('per_page', 15);
        $perPage = ($perPage > 0 && $perPage <= 100) ? $perPage : 15;
        $page = max(1, (int) $request->query('page', 1));

        $status = $request->query('status');
        $result = $this->progressService->listProgress($context, $hole, $perPage, $page, is_string($status) ? $status : null, $correlation->id);
        $summary = $this->operationalService->getSummary($context, $hole, $correlation->id, $resolved['origin_id']);

        return response()->json([
            'data' => ProgressReportPresenter::many($result['items'], $context->tenantId, $result['origin_id'] ?? $resolved['origin_id']),
            'summary' => $summary,
            'pagination' => self::pagination($page, $perPage, $result['total']),
            'request_id' => $resolved['request_id'] ?? $correlation->id,
            'correlation_id' => $correlation->id,
        ]);
    }

    public function show(Request $request, string $id, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->viewAny($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado']], 403);
        }

        $log = $this->progressService->getProgress($context, $id, $correlation->id);

        return response()->json([
            'data' => ProgressReportPresenter::one($log, $context->tenantId, $log['origin_id'] ?? null),
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

        $holeId = $request->query('hole_id');
        $perPage = (int) $request->query('per_page', 15);
        $perPage = ($perPage > 0 && $perPage <= 100) ? $perPage : 15;
        $page = max(1, (int) $request->query('page', 1));

        $result = $this->progressService->listPendingProgress($context, is_string($holeId) ? $holeId : null, $perPage, $page, $correlation->id);

        return response()->json([
            'data' => ProgressReportPresenter::many($result['items'], $context->tenantId, $result['origin_id']),
            'summary' => $result['summary'],
            'pagination' => self::pagination($page, $perPage, $result['total']),
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
            $context,
            $id,
            $validated['action'],
            $validated['review_notes'] ?? null,
            $correlation->id,
        );

        $statusEs = ($updated['status'] ?? null) === 'approved' ? 'aprobado' : 'rechazado';

        return response()->json([
            'message' => "Parte diario {$statusEs} exitosamente.",
            'data' => ProgressReportPresenter::one($updated, $context->tenantId, $updated['origin_id'] ?? null),
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

        // La cuadrilla vive en la base de la minera: se lee una vez, solo si
        // hace falta, y sirve para el permiso y para la foto del parte.
        $crew = null;
        $loadCrew = function () use (&$crew, $context, $hole, $correlation): array {
            return $crew ??= $this->assignmentService->getCrewForHole($context, $hole, $correlation->id)['crew'];
        };

        // Autorizar ANTES de procesar o devolver cualquier replay idempotente
        if (! $this->policy->create($request->user(), $context, fn () => $loadCrew()['driller']['user_id'] ?? null)) {
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
            $loadCrew(),
            $correlation->id,
        );

        $isReplay = $result['replayed'];
        $statusCode = $isReplay ? 200 : 201;

        $response = response()->json([
            'message' => $isReplay
                ? 'Avance ya registrado previamente (respuesta idempotente).'
                : 'Avance registrado exitosamente en estado pendiente de revisión por el supervisor.',
            'data' => ProgressReportPresenter::one($result['item'], $context->tenantId, $result['origin_id'] ?? $originId),
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

        $this->operationalService->initializeDepth(
            $context,
            $hole,
            $request->input('initial_depth'),
            $request->input('notes'),
            $correlation->id,
        );

        $summary = $this->operationalService->getSummary($context, $hole, $correlation->id, $resolved['origin_id']);

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

        $perPage = (int) $request->query('per_page', 25);
        $perPage = ($perPage > 0 && $perPage <= 100) ? $perPage : 25;
        $page = max(1, (int) $request->query('page', 1));

        $result = $this->progressService->listCoreLogs($context, $hole, $perPage, $page, $correlation->id);

        return response()->json([
            'data' => CoreLogPresenter::many($result['items'], $context->tenantId, $result['origin_id'] ?? $resolved['origin_id']),
            'rqd_summary' => CoreLogPresenter::summary($result['totals']),
            'pagination' => self::pagination($page, $perPage, $result['total']),
            'request_id' => $resolved['request_id'] ?? $correlation->id,
            'correlation_id' => $correlation->id,
        ]);
    }

    public function coreLogsShow(Request $request, string $hole, string $id, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->viewAny($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado']], 403);
        }

        $coreLog = $this->progressService->getCoreLog($context, $hole, $id, $correlation->id);

        return response()->json([
            'data' => CoreLogPresenter::one($coreLog, $context->tenantId, $coreLog['origin_id'] ?? null),
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

        $updated = $this->progressService->updateCoreLog($context, $hole, $id, $request->validated(), $correlation->id);

        return response()->json([
            'message' => 'Tramo de testigo y cálculo de RQD actualizados exitosamente.',
            'data' => CoreLogPresenter::one($updated, $context->tenantId, $updated['origin_id'] ?? null),
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

        $this->progressService->deleteCoreLog($context, $hole, $id, $correlation->id);

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

        $coreLog = $this->progressService->recordCoreLog($context, $hole, $request->validated(), $correlation->id);

        return response()->json([
            'message' => 'Tramo de testigo y cálculo de RQD asentados exitosamente.',
            'data' => CoreLogPresenter::one($coreLog, $context->tenantId, $coreLog['origin_id'] ?? $originId),
            'request_id' => $resolved['request_id'] ?? $correlation->id,
            'correlation_id' => $correlation->id,
        ], 201);
    }

    /**
     * @return array{current_page: int, per_page: int, total: int, last_page: int}
     */
    private static function pagination(int $page, int $perPage, int $total): array
    {
        return [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
        ];
    }
}
