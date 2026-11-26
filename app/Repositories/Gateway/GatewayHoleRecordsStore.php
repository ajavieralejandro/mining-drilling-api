<?php

namespace App\Repositories\Gateway;

use App\Domain\Repositories\HoleRecordsStore;
use App\Services\Connector\CommandDispatcher;
use App\Support\GatewayException;
use App\Support\TenantContext;

/**
 * Hole records through the tenant's connector. Each call dispatches one
 * operation, reads the result and purges the command (ADR-04): nothing the
 * driller reported stays in the control plane database.
 */
class GatewayHoleRecordsStore implements HoleRecordsStore
{
    use RunsConnectorRecords;

    public const CONFLICT_MESSAGE = 'Conflicto de idempotencia: la clave provista ya fue utilizada con datos o destino diferentes.';

    public function __construct(private readonly CommandDispatcher $dispatcher) {}

    public function holeState(TenantContext $context, string $holeId, string $correlationId): array
    {
        $result = $this->run($context, 'hole_state.get@1', ['hole_id' => $holeId], $correlationId);

        return [
            'hole_status' => (string) ($result['hole_status'] ?? ''),
            'state' => is_array($result['state'] ?? null) ? $result['state'] : null,
            'stats' => [
                'total_logs' => (int) ($result['stats']['total_logs'] ?? 0),
                'last_logged_at' => $result['stats']['last_logged_at'] ?? null,
            ],
            'origin_id' => $result['origin_id'] ?? null,
        ];
    }

    public function initializeDepth(TenantContext $context, string $holeId, string $depth, ?string $notes, string $correlationId): array
    {
        $result = $this->write($context, 'hole_state.init@1', array_filter([
            'hole_id' => $holeId,
            'initial_depth' => $depth,
            'user_id' => (string) $context->userId,
            'notes' => $notes,
        ], fn ($v) => $v !== null), $correlationId);

        return ($result['state'] ?? []) + ['origin_id' => $result['origin_id'] ?? null];
    }

    public function recordProgress(TenantContext $context, string $holeId, array $report, string $correlationId): array
    {
        $payload = array_filter($report + [
            'hole_id' => $holeId,
            'user_id' => (string) $context->userId,
        ], fn ($v) => $v !== null && $v !== '');

        $result = $this->write($context, 'progress.create@2', $payload, $correlationId);

        return [
            'item' => $this->item($result),
            'replayed' => ($result['outcome'] ?? null) === 'replayed',
            'origin_id' => $result['origin_id'] ?? null,
        ];
    }

    public function queryProgress(TenantContext $context, ?string $holeId, ?string $status, int $page, int $perPage, string $correlationId): array
    {
        $result = $this->run($context, 'progress.list@2', array_filter([
            'hole_id' => $holeId,
            'status' => $status,
            'page' => $page,
            'per_page' => $perPage,
        ], fn ($v) => $v !== null && $v !== ''), $correlationId);

        $items = $result['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            throw new GatewayException('INVALID_RESULT', 'Connector returned an invalid result', 502, null, $correlationId);
        }

        return [
            'items' => $items,
            'total' => (int) ($result['total'] ?? count($items)),
            'totals' => [
                'count' => (int) ($result['totals']['count'] ?? 0),
                'total_meters' => (string) ($result['totals']['total_meters'] ?? '0.00'),
                'holes_count' => (int) ($result['totals']['holes_count'] ?? 0),
            ],
            'origin_id' => $result['origin_id'] ?? null,
        ];
    }

    public function getProgress(TenantContext $context, string $id, string $correlationId): array
    {
        $result = $this->run($context, 'progress.get@2', ['id' => $id], $correlationId);

        return $this->item($result) + ['origin_id' => $result['origin_id'] ?? null];
    }

    public function reviewProgress(TenantContext $context, string $id, string $action, ?string $notes, string $correlationId): array
    {
        $result = $this->write($context, 'progress.review@1', array_filter([
            'id' => $id,
            'action' => $action,
            'notes' => $notes,
            'reviewer_id' => (string) $context->userId,
        ], fn ($v) => $v !== null && $v !== ''), $correlationId);

        return $this->item($result) + ['origin_id' => $result['origin_id'] ?? null];
    }

    public function listCoreLogs(TenantContext $context, string $holeId, int $page, int $perPage, string $correlationId): array
    {
        $result = $this->run($context, 'core_logs.list@1', [
            'hole_id' => $holeId,
            'page' => $page,
            'per_page' => $perPage,
        ], $correlationId);

        $items = $result['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            throw new GatewayException('INVALID_RESULT', 'Connector returned an invalid result', 502, null, $correlationId);
        }

        return [
            'items' => $items,
            'total' => (int) ($result['total'] ?? count($items)),
            'totals' => is_array($result['totals'] ?? null) ? $result['totals'] : [],
            'origin_id' => $result['origin_id'] ?? null,
        ];
    }

    public function createCoreLog(TenantContext $context, string $holeId, array $run, string $correlationId): array
    {
        $payload = array_filter($run + [
            'hole_id' => $holeId,
            'user_id' => (string) $context->userId,
        ], fn ($v) => $v !== null && $v !== '');

        $result = $this->write($context, 'core_logs.create@1', $payload, $correlationId);

        return $this->item($result) + ['origin_id' => $result['origin_id'] ?? null];
    }

    public function getCoreLog(TenantContext $context, string $holeId, string $id, string $correlationId): array
    {
        $result = $this->run($context, 'core_logs.get@1', ['hole_id' => $holeId, 'id' => $id], $correlationId);

        return $this->item($result) + ['origin_id' => $result['origin_id'] ?? null];
    }

    public function updateCoreLog(TenantContext $context, string $holeId, string $id, array $changes, string $correlationId): array
    {
        $result = $this->write($context, 'core_logs.update@1', ['hole_id' => $holeId, 'id' => $id] + $changes, $correlationId);

        return $this->item($result) + ['origin_id' => $result['origin_id'] ?? null];
    }

    public function deleteCoreLog(TenantContext $context, string $holeId, string $id, string $correlationId): void
    {
        $this->write($context, 'core_logs.delete@1', ['hole_id' => $holeId, 'id' => $id], $correlationId);
    }

    public function crew(TenantContext $context, string $holeId, string $correlationId): array
    {
        $result = $this->run($context, 'assignments.list@1', ['hole_id' => $holeId], $correlationId);

        return [
            'active' => self::rows($result['active'] ?? [], $correlationId),
            'history' => self::rows($result['history'] ?? [], $correlationId),
            'origin_id' => $result['origin_id'] ?? null,
        ];
    }

    public function assignmentsOf(TenantContext $context, int $userId, string $correlationId): array
    {
        $result = $this->run($context, 'assignments.by_user@1', ['user_id' => (string) $userId], $correlationId);

        return [
            'active' => self::rows($result['active'] ?? [], $correlationId),
            'origin_id' => $result['origin_id'] ?? null,
        ];
    }

    public function assignCrew(TenantContext $context, string $holeId, string $mode, array $roles, array $extra, string $correlationId): array
    {
        $payload = [
            'hole_id' => $holeId,
            'assigned_by' => (string) $context->userId,
            'mode' => $mode,
            // JSON object even with one key; ids travel as strings.
            'roles' => array_map(fn ($user) => $user === null ? null : (string) $user, $roles),
        ] + array_filter($extra, fn ($v) => $v !== null && $v !== '');

        $result = $this->write($context, 'assignments.put@1', $payload, $correlationId);

        return [
            'active' => self::rows($result['active'] ?? [], $correlationId),
            'origin_id' => $result['origin_id'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function item(array $result): array
    {
        if (! is_array($result['item'] ?? null) || ! isset($result['item']['id'])) {
            throw new GatewayException('INVALID_RESULT', 'Connector returned an invalid result', 502);
        }

        return $result['item'];
    }
}
