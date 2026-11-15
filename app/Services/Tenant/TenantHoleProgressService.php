<?php

namespace App\Services\Tenant;

use App\Models\TenantCoreLog;
use App\Models\TenantHoleProgressLog;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantHoleProgressService
{
    public function __construct(
        private readonly TenantHoleOperationalStateService $operationalService,
        private readonly TenantHoleAssignmentService $assignmentService,
    ) {}

    /**
     * Lista los partes diarios de avance del pozo con paginación cronológica descendente.
     */
    public function listProgress(
        string $tenantId,
        string $originId,
        string $holeId,
        int $perPage = 15,
        int $page = 1,
        ?string $status = null
    ): LengthAwarePaginator {
        $query = TenantHoleProgressLog::with(['user', 'reviewer'])
            ->forWell($tenantId, $originId, $holeId);

        if ($status !== null && trim($status) !== '') {
            $query->where('status', trim($status));
        }

        return $query->orderByDesc('logged_at')
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Obtiene un parte diario específico por ID.
     */
    public function getProgress(string $tenantId, string $progressId): TenantHoleProgressLog
    {
        return TenantHoleProgressLog::with(['user', 'reviewer', 'operationalState'])
            ->where('tenant_id', $tenantId)
            ->where('id', $progressId)
            ->firstOrFail();
    }

    /**
     * Resumen de partes pendientes para el panel del supervisor.
     */
    public function getPendingSummary(string $tenantId, ?string $originId = null, ?string $holeId = null): array
    {
        $query = TenantHoleProgressLog::where('tenant_id', $tenantId)
            ->where('status', 'pending_review');

        if ($originId !== null && trim($originId) !== '') {
            $query->where('origin_id', $originId);
        }

        if ($holeId !== null && trim($holeId) !== '') {
            $query->where('hole_id', $holeId);
        }

        $logs = $query->get(['depth_from', 'depth_to', 'hole_id']);
        $totalPending = $logs->count();
        $totalMeters = '0.00';
        $distinctHoles = [];

        foreach ($logs as $log) {
            $advance = bcsub(sprintf('%.2f', $log->depth_to), sprintf('%.2f', $log->depth_from), 2);
            $totalMeters = bcadd($totalMeters, $advance, 2);
            $distinctHoles[$log->hole_id] = true;
        }

        return [
            'total_pending' => $totalPending,
            'total_pending_meters' => $totalMeters,
            'holes_count' => count($distinctHoles),
        ];
    }

    /**
     * Lista los partes diarios en estado pendiente de revisión (pending_review) para el supervisor.
     */
    public function listPendingProgress(
        string $tenantId,
        ?string $originId = null,
        ?string $holeId = null,
        int $perPage = 15,
        int $page = 1
    ): LengthAwarePaginator {
        $query = TenantHoleProgressLog::with(['user', 'operationalState'])
            ->where('tenant_id', $tenantId)
            ->where('status', 'pending_review');

        if ($originId !== null && trim($originId) !== '') {
            $query->where('origin_id', $originId);
        }

        if ($holeId !== null && trim($holeId) !== '') {
            $query->where('hole_id', $holeId);
        }

        return $query->orderByDesc('logged_at')
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Revisa y aprueba o rechaza un parte diario registrado por el perforista.
     */
    public function reviewProgress(
        string $tenantId,
        string $progressId,
        string $action,
        ?string $notes,
        int $reviewerUserId
    ): TenantHoleProgressLog {
        return DB::transaction(function () use ($tenantId, $progressId, $action, $notes, $reviewerUserId) {
            $log = TenantHoleProgressLog::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $progressId)
                ->lockForUpdate()
                ->firstOrFail();

            $newStatus = $action === 'approve' ? 'approved' : 'rejected';
            $log->status = $newStatus;
            $log->reviewed_by = $reviewerUserId;
            $log->reviewed_at = now();
            $log->review_notes = $notes;
            $log->save();

            return $log->load(['user', 'reviewer']);
        });
    }

    /**
     * Registra un nuevo tramo de avance de perforación con estricto control de idempotencia,
     * concurrencia transaccional, precisión decimal sin floats y snapshot histórico de cuadrilla.
     */
    public function recordProgress(
        TenantContext $context,
        string $originId,
        string $holeId,
        array $data,
        ?array $gatewayHoleItem = null
    ): array {
        $idempotencyKey = trim((string) $data['idempotency_key']);

        // Normalización estricta de cotas y datos de entrada
        $depthFrom = sprintf('%.2f', $data['depth_from']);
        $depthTo = sprintf('%.2f', $data['depth_to']);
        $hoursWorked = isset($data['hours_worked']) ? sprintf('%.2f', $data['hours_worked']) : null;
        $shift = isset($data['shift']) && is_string($data['shift']) ? strtoupper(trim($data['shift'])) : null;
        $loggedAt = isset($data['logged_at']) ? Carbon::parse($data['logged_at'])->toISOString() : now()->toISOString();
        $observations = isset($data['observations']) && is_string($data['observations']) && trim($data['observations']) !== ''
            ? trim($data['observations'])
            : null;

        $normalizedContent = [
            'depth_from' => $depthFrom,
            'depth_to' => $depthTo,
            'hours_worked' => $hoursWorked,
            'shift' => $shift,
            'logged_at' => $loggedAt,
            'observations' => $observations,
        ];

        $payloadHash = hash('sha256', json_encode($normalizedContent, JSON_THROW_ON_ERROR));

        // 1. Verificación previa de idempotencia (Fast path tras autorización)
        $existing = TenantHoleProgressLog::with(['user', 'reviewer'])
            ->where('tenant_id', $context->tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            $sameWell = ($existing->origin_id === $originId && $existing->hole_id === $holeId);
            $samePayload = ($existing->payload_hash === $payloadHash);

            if ($sameWell && $samePayload) {
                return [
                    'progress' => $existing,
                    'idempotent_replay' => true,
                ];
            }

            // Misma clave con contenido o pozo diferente: HTTP 409
            throw new HttpException(409, 'Conflicto de idempotencia: la clave provista ya fue utilizada con datos o destino diferentes.');
        }

        // 2. Transacción atómica con reintentos para soportar PostgreSQL, MySQL y SQLite
        try {
            return DB::transaction(function () use ($context, $originId, $holeId, $data, $idempotencyKey, $payloadHash, $depthFrom, $depthTo, $hoursWorked, $shift, $loggedAt, $observations, $gatewayHoleItem) {
                // Re-verificación atómica bajo lock para carreras concurrentes de idempotencia
                $existingCheck = TenantHoleProgressLog::with(['user', 'reviewer'])
                    ->where('tenant_id', $context->tenantId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingCheck !== null) {
                    $sameWell = ($existingCheck->origin_id === $originId && $existingCheck->hole_id === $holeId);
                    $samePayload = ($existingCheck->payload_hash === $payloadHash);

                    if ($sameWell && $samePayload) {
                        return [
                            'progress' => $existingCheck,
                            'idempotent_replay' => true,
                        ];
                    }

                    throw new HttpException(409, 'Conflicto de idempotencia: la clave provista ya fue utilizada con datos o destino diferentes.');
                }

                // Bloqueo pesimista de la fila operacional estable del pozo con estado real del Gateway
                $state = $this->operationalService->resolveAndLock($context->tenantId, $originId, $holeId, $gatewayHoleItem);

                // Comprobar estado operable
                if (! $state->isOperable()) {
                    throw ValidationException::withMessages([
                        'status' => "El pozo se encuentra en estado '{$state->status}' en el origen y no admite nuevos registros de avance.",
                    ]);
                }

                // Inicialización de cota si el pozo no tiene cota previa inicializada
                if (! $state->depth_initialized) {
                    if ($gatewayHoleItem && isset($gatewayHoleItem['reached_depth']) && $gatewayHoleItem['reached_depth'] !== null) {
                        $init = sprintf('%.2f', $gatewayHoleItem['reached_depth']);
                        $state->initial_depth = $init;
                        $state->current_depth = $init;
                        $state->depth_initialized = true;
                        $state->depth_initialized_at = now();
                        $state->depth_initialized_by = null;
                        $state->save();
                    } elseif (isset($data['initial_depth']) && in_array($context->role, ['admin', 'supervisor'], true)) {
                        $init = sprintf('%.2f', $data['initial_depth']);
                        $state->initial_depth = $init;
                        $state->current_depth = $init;
                        $state->depth_initialized = true;
                        $state->depth_initialized_at = now();
                        $state->depth_initialized_by = $context->userId;
                        $state->save();
                    } else {
                        throw ValidationException::withMessages([
                            'depth_from' => 'La profundidad operativa del pozo no ha sido inicializada. Un supervisor o administrador debe establecer la profundidad inicial antes de registrar avances.',
                        ]);
                    }
                }

                // Validación de continuidad estricta sin floats (BCMath escala 2)
                $currentDepth = sprintf('%.2f', $state->current_depth);

                if (bccomp($depthFrom, $currentDepth, 2) !== 0) {
                    throw ValidationException::withMessages([
                        'depth_from' => "La profundidad inicial ({$depthFrom} m) debe coincidir exactamente con la profundidad operativa actual ({$currentDepth} m).",
                    ]);
                }

                if (bccomp($depthTo, $depthFrom, 2) <= 0) {
                    throw ValidationException::withMessages([
                        'depth_to' => "La profundidad final ({$depthTo} m) debe ser estrictamente mayor que la profundidad inicial ({$depthFrom} m).",
                    ]);
                }

                // Capturar snapshot inmutable de las asignaciones activas aplicables a este avance
                $crewSnapshot = $this->assignmentService->getActiveCrewSnapshot($context->tenantId, $originId, $holeId);

                // Asentar el parte diario (estado por defecto: pending_review)
                $log = TenantHoleProgressLog::create([
                    'tenant_id' => $context->tenantId,
                    'operational_state_id' => $state->id,
                    'origin_id' => $originId,
                    'hole_id' => $holeId,
                    'user_id' => $context->userId,
                    'depth_from' => $depthFrom,
                    'depth_to' => $depthTo,
                    'depth_current' => $depthTo,
                    'hours_worked' => $hoursWorked,
                    'shift' => $shift,
                    'status' => 'pending_review',
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'crew_snapshot' => $crewSnapshot,
                    'logged_at' => $loggedAt,
                    'observations' => $observations,
                ]);

                // Actualizar la cota alcanzada del pozo en la misma transacción
                $state->current_depth = $depthTo;
                $state->save();

                return [
                    'progress' => $log->load(['user', 'reviewer']),
                    'idempotent_replay' => false,
                ];
            }, attempts: 5);
        } catch (QueryException $e) {
            // Manejar carrera en la restricción única de idempotencia sin devolver 500
            if ($this->isExpectedIdempotencyCollision($e)) {
                $existingCheck = TenantHoleProgressLog::with(['user', 'reviewer'])
                    ->where('tenant_id', $context->tenantId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingCheck !== null && $existingCheck->origin_id === $originId && $existingCheck->hole_id === $holeId && $existingCheck->payload_hash === $payloadHash) {
                    return [
                        'progress' => $existingCheck,
                        'idempotent_replay' => true,
                    ];
                }

                throw new HttpException(409, 'Conflicto de idempotencia: la clave provista ya fue utilizada con datos o destino diferentes.');
            }

            throw $e;
        }
    }

    /**
     * Lista el historial de testigos y RQD en loguera de geología.
     */
    public function listCoreLogs(
        string $tenantId,
        string $originId,
        string $holeId,
        int $perPage = 25,
        int $page = 1
    ): LengthAwarePaginator {
        return TenantCoreLog::with('loggedByUser')
            ->forWell($tenantId, $originId, $holeId)
            ->orderByDesc('run_from')
            ->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Asienta un nuevo tramo en loguera calculando RQD y porcentaje recuperado.
     */
    public function recordCoreLog(
        TenantContext $context,
        string $originId,
        string $holeId,
        array $data
    ): TenantCoreLog {
        $metrics = TenantCoreLog::calculateMetrics(
            $data['run_from'],
            $data['run_to'],
            $data['recovered_meters'],
            $data['rqd_meters']
        );

        $loggedAt = isset($data['logged_at']) ? Carbon::parse($data['logged_at'])->toISOString() : now()->toISOString();

        return TenantCoreLog::create([
            'tenant_id' => $context->tenantId,
            'origin_id' => $originId,
            'hole_id' => $holeId,
            'box_number' => isset($data['box_number']) ? trim((string) $data['box_number']) : null,
            'run_from' => sprintf('%.2f', $data['run_from']),
            'run_to' => sprintf('%.2f', $data['run_to']),
            'drill_length' => $metrics['drill_length'],
            'recovered_meters' => $metrics['recovered_meters'],
            'rqd_meters' => $metrics['rqd_meters'],
            'rqd_percentage' => $metrics['rqd_percentage'],
            'core_loss_meters' => $metrics['core_loss_meters'],
            'logged_by' => $context->userId,
            'logged_at' => $loggedAt,
            'geology_notes' => isset($data['geology_notes']) && trim((string) $data['geology_notes']) !== '' ? trim((string) $data['geology_notes']) : null,
            'photo_url' => isset($data['photo_url']) && trim((string) $data['photo_url']) !== '' ? trim((string) $data['photo_url']) : null,
        ])->load('loggedByUser');
    }

    /**
     * Obtiene un tramo de testigo por ID.
     */
    public function getCoreLog(string $tenantId, string $coreLogId): TenantCoreLog
    {
        return TenantCoreLog::with('loggedByUser')
            ->where('tenant_id', $tenantId)
            ->where('id', $coreLogId)
            ->firstOrFail();
    }

    /**
     * Actualiza un tramo de testigo existente y recalcula métricas si cambian los metros.
     */
    public function updateCoreLog(string $tenantId, string $coreLogId, array $data): TenantCoreLog
    {
        $coreLog = $this->getCoreLog($tenantId, $coreLogId);

        if (array_key_exists('box_number', $data)) {
            $coreLog->box_number = $data['box_number'] !== null ? trim((string) $data['box_number']) : null;
        }

        if (array_key_exists('geology_notes', $data)) {
            $coreLog->geology_notes = $data['geology_notes'] !== null ? trim((string) $data['geology_notes']) : null;
        }

        if (array_key_exists('photo_url', $data)) {
            $coreLog->photo_url = $data['photo_url'] !== null ? trim((string) $data['photo_url']) : null;
        }

        if (isset($data['logged_at'])) {
            $coreLog->logged_at = Carbon::parse($data['logged_at'])->toISOString();
        }

        if (isset($data['recovered_meters']) || isset($data['rqd_meters'])) {
            $recovered = $data['recovered_meters'] ?? $coreLog->recovered_meters;
            $rqd = $data['rqd_meters'] ?? $coreLog->rqd_meters;

            $metrics = TenantCoreLog::calculateMetrics(
                $coreLog->run_from,
                $coreLog->run_to,
                $recovered,
                $rqd
            );

            $coreLog->recovered_meters = $metrics['recovered_meters'];
            $coreLog->rqd_meters = $metrics['rqd_meters'];
            $coreLog->rqd_percentage = $metrics['rqd_percentage'];
            $coreLog->core_loss_meters = $metrics['core_loss_meters'];
        }

        $coreLog->save();

        return $coreLog->load('loggedByUser');
    }

    /**
     * Elimina un tramo de testigo en loguera.
     */
    public function deleteCoreLog(string $tenantId, string $coreLogId): void
    {
        $coreLog = $this->getCoreLog($tenantId, $coreLogId);
        $coreLog->delete();
    }

    private function isExpectedIdempotencyCollision(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        $message = strtolower($e->getMessage());

        $isUniqueSqlState = in_array($code, ['23000', '23505'], true);
        if (! $isUniqueSqlState) {
            return false;
        }

        return str_contains($message, 'th_progress_tenant_idempotency_uniq')
            || (str_contains($message, 'tenant_hole_progress_logs') && str_contains($message, 'idempotency_key'));
    }
}
