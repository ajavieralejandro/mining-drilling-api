<?php

namespace App\Services\Tenant;

use App\Models\TenantHoleOperationalState;
use App\Models\TenantHoleProgressLog;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class TenantHoleOperationalStateService
{
    /**
     * Resuelve y bloquea de manera pesimista la fila del estado operacional del pozo.
     * Exige ejecutarse dentro de una transacción activa.
     * Utiliza el estado real verificado del Gateway (no asume in_progress).
     * En caso de carrera, captura exclusivamente la colisión de unicidad esperada y propaga cualquier otro error.
     *
     * @param  array<string, mixed>  $gatewayHoleItem  Datos validados del pozo desde el Gateway
     *
     * @throws ValidationException|QueryException|LogicException
     */
    public function resolveAndLock(
        string $tenantId,
        string $originId,
        string $holeId,
        array $gatewayHoleItem
    ): TenantHoleOperationalState {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('resolveAndLock debe ser invocado dentro de una transacción de base de datos activa.');
        }

        $gatewayStatus = isset($gatewayHoleItem['status']) && is_string($gatewayHoleItem['status']) && $gatewayHoleItem['status'] !== ''
            ? strtolower(trim($gatewayHoleItem['status']))
            : null;

        if ($gatewayStatus === null) {
            throw ValidationException::withMessages([
                'status' => 'El estado del pozo no pudo ser verificado en el origen a través del Gateway.',
            ]);
        }

        try {
            $state = TenantHoleOperationalState::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'origin_id' => $originId,
                    'hole_id' => $holeId,
                ],
                [
                    'id' => (string) Str::ulid(),
                    'status' => $gatewayStatus,
                    'depth_initialized' => false,
                ]
            );
        } catch (QueryException $e) {
            if (! $this->isExpectedUniqueCollision($e)) {
                throw $e;
            }

            // Recuperar el registro preexistente solo ante colisión de unicidad esperada
            $state = TenantHoleOperationalState::query()
                ->where('tenant_id', $tenantId)
                ->where('origin_id', $originId)
                ->where('hole_id', $holeId)
                ->firstOrFail();
        }

        // Bloqueo pesimista sobre la fila estable del pozo dentro de la transacción
        $state = TenantHoleOperationalState::query()
            ->where('id', $state->id)
            ->lockForUpdate()
            ->firstOrFail();

        // Sincronizar el estado real del pozo reportado por el Gateway
        if ($state->status !== $gatewayStatus) {
            $state->status = $gatewayStatus;
            $state->save();
        }

        return $state;
    }

    /**
     * Comprueba si la QueryException corresponde específicamente a la violación
     * de la restricción única esperada para la tupla (tenant_id, origin_id, hole_id).
     */
    private function isExpectedUniqueCollision(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        $message = strtolower($e->getMessage());

        $isUniqueSqlState = in_array($code, ['23000', '23505'], true);
        if (! $isUniqueSqlState) {
            return false;
        }

        return str_contains($message, 'th_operational_state_tenant_origin_hole_uniq')
            || (str_contains($message, 'tenant_hole_operational_states') && (str_contains($message, 'unique') || str_contains($message, 'duplicate')));
    }

    /**
     * Inicialización explícita y auditable de la profundidad base del pozo.
     * Solo aplicable por Administrador o Supervisor.
     *
     * @param  array<string, mixed>  $gatewayHoleItem
     */
    public function initializeDepth(
        TenantContext $context,
        string $originId,
        string $holeId,
        mixed $initialDepth,
        ?string $notes,
        array $gatewayHoleItem
    ): TenantHoleOperationalState {
        $formatted = sprintf('%.2f', $initialDepth);

        return DB::transaction(function () use ($context, $originId, $holeId, $formatted, $notes, $gatewayHoleItem) {
            $state = $this->resolveAndLock($context->tenantId, $originId, $holeId, $gatewayHoleItem);

            if (! $state->isOperable()) {
                throw ValidationException::withMessages([
                    'status' => "El pozo se encuentra en estado '{$state->status}' en el origen y no permite inicializar profundidad.",
                ]);
            }

            // Si ya tiene partes de perforación asentados, no se puede alterar la cota inicial
            $hasLogs = TenantHoleProgressLog::query()
                ->where('tenant_id', $context->tenantId)
                ->where('origin_id', $originId)
                ->where('hole_id', $holeId)
                ->exists();

            if ($hasLogs) {
                throw ValidationException::withMessages([
                    'initial_depth' => 'No se puede modificar la profundidad inicial porque el pozo ya posee partes de avance registrados.',
                ]);
            }

            $state->initial_depth = $formatted;
            $state->current_depth = $formatted;
            $state->depth_initialized = true;
            $state->depth_initialized_at = now();
            $state->depth_initialized_by = $context->userId;
            $state->notes = $notes;
            $state->save();

            return $state;
        }, attempts: 5);
    }

    /**
     * Resumen operativo consolidado del pozo para encabezados web y paneles de control.
     */
    public function getSummary(string $tenantId, string $originId, string $holeId): array
    {
        $state = TenantHoleOperationalState::query()
            ->where('tenant_id', $tenantId)
            ->where('origin_id', $originId)
            ->where('hole_id', $holeId)
            ->first();

        $logsQuery = TenantHoleProgressLog::query()
            ->where('tenant_id', $tenantId)
            ->where('origin_id', $originId)
            ->where('hole_id', $holeId);

        $totalLogs = $logsQuery->count();
        $lastLog = (clone $logsQuery)->latest('logged_at')->first();

        $initialDepth = $state?->initial_depth !== null ? sprintf('%.2f', $state->initial_depth) : null;
        $currentDepth = $state?->current_depth !== null ? sprintf('%.2f', $state->current_depth) : null;

        $totalMeters = '0.00';
        if ($initialDepth !== null && $currentDepth !== null) {
            $totalMeters = bcsub($currentDepth, $initialDepth, 2);
            if (bccomp($totalMeters, '0.00', 2) < 0) {
                $totalMeters = '0.00';
            }
        }

        return [
            'hole_id' => $holeId,
            'origin_id' => $originId,
            'status' => $state?->status ?? TenantHoleOperationalState::STATUS_IN_PROGRESS,
            'is_operable' => $state ? $state->isOperable() : false,
            'depth_initialized' => (bool) ($state?->depth_initialized ?? false),
            'initial_depth' => $initialDepth,
            'current_depth' => $currentDepth,
            'total_advance_meters' => $totalMeters,
            'total_logs' => $totalLogs,
            'last_logged_at' => $lastLog?->logged_at?->toIso8601String(),
            'initialized_at' => $state?->depth_initialized_at?->toIso8601String(),
            'initialized_by' => $state?->depth_initialized_by,
            'notes' => $state?->notes,
        ];
    }
}
