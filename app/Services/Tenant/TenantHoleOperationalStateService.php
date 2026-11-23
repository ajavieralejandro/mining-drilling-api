<?php

namespace App\Services\Tenant;

use App\Domain\Repositories\HoleRecordsStore;
use App\Models\TenantHoleOperationalState;
use App\Support\TenantContext;

class TenantHoleOperationalStateService
{
    public function __construct(private readonly HoleRecordsStore $records) {}

    /**
     * Inicialización explícita de la cota base del pozo, en la base de la
     * minera. El conector rechaza la operación si el pozo no está en curso o
     * si ya tiene partes registrados.
     *
     * @return array<string, mixed>
     */
    public function initializeDepth(
        TenantContext $context,
        string $holeId,
        mixed $initialDepth,
        ?string $notes,
        string $correlationId,
    ): array {
        return $this->records->initializeDepth($context, $holeId, sprintf('%.2f', $initialDepth), $notes, $correlationId);
    }

    /**
     * Resumen operativo del pozo para la web, armado con el estado que guarda
     * la base de la minera.
     *
     * @return array<string, mixed>
     */
    public function getSummary(TenantContext $context, string $holeId, string $correlationId, ?string $originId = null): array
    {
        $result = $this->records->holeState($context, $holeId, $correlationId);
        $state = $result['state'];

        $initialDepth = isset($state['initial_depth']) ? sprintf('%.2f', $state['initial_depth']) : null;
        $currentDepth = isset($state['current_depth']) ? sprintf('%.2f', $state['current_depth']) : null;

        $totalMeters = '0.00';
        if ($initialDepth !== null && $currentDepth !== null) {
            $totalMeters = bcsub($currentDepth, $initialDepth, 2);
            if (bccomp($totalMeters, '0.00', 2) < 0) {
                $totalMeters = '0.00';
            }
        }

        $status = $result['hole_status'] !== '' ? strtolower($result['hole_status']) : TenantHoleOperationalState::STATUS_IN_PROGRESS;
        $initializedBy = $state['initialized_by'] ?? null;

        return [
            'hole_id' => $holeId,
            'origin_id' => $result['origin_id'] ?? $originId,
            'status' => $status,
            'is_operable' => $status === TenantHoleOperationalState::STATUS_IN_PROGRESS,
            'depth_initialized' => (bool) ($state['depth_initialized'] ?? false),
            'initial_depth' => $initialDepth,
            'current_depth' => $currentDepth,
            'total_advance_meters' => $totalMeters,
            'total_logs' => $result['stats']['total_logs'],
            'last_logged_at' => $result['stats']['last_logged_at'],
            'initialized_at' => $state['initialized_at'] ?? null,
            'initialized_by' => is_numeric($initializedBy) ? (int) $initializedBy : null,
            'notes' => $state['notes'] ?? null,
        ];
    }
}
