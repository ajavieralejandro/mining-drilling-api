<?php

namespace App\Services\Tenant;

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
    public function listProgress(string $tenantId, string $originId, string $holeId, int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        return TenantHoleProgressLog::with('user')
            ->forWell($tenantId, $originId, $holeId)
            ->orderByDesc('logged_at')
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $page);
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
        $shift = isset($data['shift']) && is_string($data['shift']) ? strtoupper(trim($data['shift'])) : null;
        $loggedAt = isset($data['logged_at']) ? Carbon::parse($data['logged_at'])->toISOString() : now()->toISOString();
        $observations = isset($data['observations']) && is_string($data['observations']) && trim($data['observations']) !== ''
            ? trim($data['observations'])
            : null;

        $normalizedContent = [
            'depth_from' => $depthFrom,
            'depth_to' => $depthTo,
            'shift' => $shift,
            'logged_at' => $loggedAt,
            'observations' => $observations,
        ];

        $payloadHash = hash('sha256', json_encode($normalizedContent, JSON_THROW_ON_ERROR));

        // 1. Verificación previa de idempotencia (Fast path tras autorización)
        $existing = TenantHoleProgressLog::with('user')
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
            return DB::transaction(function () use ($context, $originId, $holeId, $data, $idempotencyKey, $payloadHash, $depthFrom, $depthTo, $shift, $loggedAt, $observations, $gatewayHoleItem) {
                // Re-verificación atómica bajo lock para carreras concurrentes de idempotencia
                $existingCheck = TenantHoleProgressLog::with('user')
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

                // Asentar el parte diario
                $log = TenantHoleProgressLog::create([
                    'tenant_id' => $context->tenantId,
                    'operational_state_id' => $state->id,
                    'origin_id' => $originId,
                    'hole_id' => $holeId,
                    'user_id' => $context->userId,
                    'depth_from' => $depthFrom,
                    'depth_to' => $depthTo,
                    'depth_current' => $depthTo,
                    'shift' => $shift,
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
                    'progress' => $log->load('user'),
                    'idempotent_replay' => false,
                ];
            }, attempts: 5);
        } catch (QueryException $e) {
            // Manejar carrera en la restricción única de idempotencia sin devolver 500
            if ($this->isExpectedIdempotencyCollision($e)) {
                $existingCheck = TenantHoleProgressLog::with('user')
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
