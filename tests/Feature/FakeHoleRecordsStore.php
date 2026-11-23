<?php

namespace Tests\Feature;

use App\Domain\Repositories\HoleRecordsStore;
use App\Repositories\Gateway\GatewayHoleRecordsStore;
use App\Support\GatewayException;
use App\Support\TenantContext;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Stands in for the client database behind the connector. It keeps the
 * minimum the HTTP tests need; the business rules themselves are tested in
 * the connector (internal/protocol/records_test.go).
 */
class FakeHoleRecordsStore implements HoleRecordsStore
{
    /** @var array<string, array<string, mixed>> */
    public array $reports = [];

    /** @var array<string, array<string, mixed>> */
    public array $states = [];

    /** @var array<string, string> */
    public array $holeStatus = [];

    /** @var array<string, array<string, mixed>> */
    public array $coreLogs = [];

    /** @var list<array<string, mixed>> */
    public array $assignments = [];

    /** @var list<array{op: string, payload: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(public string $originId = 'orig-1') {}

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function seedReport(array $overrides = []): array
    {
        $report = array_merge([
            'id' => 'prog_'.count($this->reports),
            'hole_id' => 'POZO-01',
            'user_id' => '1',
            'depth_from' => '10.00',
            'depth_to' => '14.50',
            'depth_current' => '14.50',
            'shift' => 'A',
            'hours_worked' => '7.50',
            'status' => 'pending_review',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => null,
            'crew_snapshot' => null,
            'observations' => null,
            'idempotency_key' => 'idem-'.count($this->reports),
            'payload_hash' => str_repeat('a', 64),
            'logged_at' => '2026-11-10T12:00:00Z',
            'created_at' => '2026-11-10T12:00:00Z',
        ], $overrides);
        $this->reports[$report['id']] = $report;

        return $report;
    }

    public function holeState(TenantContext $context, string $holeId, string $correlationId): array
    {
        $this->calls[] = ['op' => 'hole_state.get@1', 'payload' => ['hole_id' => $holeId]];
        $reports = array_filter($this->reports, fn ($r) => $r['hole_id'] === $holeId);

        return [
            'hole_status' => $this->holeStatus[$holeId] ?? 'in_progress',
            'state' => $this->states[$holeId] ?? null,
            'stats' => [
                'total_logs' => count($reports),
                'last_logged_at' => $reports === [] ? null : max(array_column($reports, 'logged_at')),
            ],
            'origin_id' => $this->originId,
        ];
    }

    public function initializeDepth(TenantContext $context, string $holeId, string $depth, ?string $notes, string $correlationId): array
    {
        $this->calls[] = ['op' => 'hole_state.init@1', 'payload' => compact('holeId', 'depth', 'notes')];
        if (array_filter($this->reports, fn ($r) => $r['hole_id'] === $holeId) !== []) {
            throw ValidationException::withMessages(['initial_depth' => 'No se puede modificar la profundidad inicial porque el pozo ya posee partes de avance registrados.']);
        }
        $this->states[$holeId] = [
            'hole_id' => $holeId,
            'depth_initialized' => true,
            'initial_depth' => $depth,
            'current_depth' => $depth,
            'initialized_by' => (string) $context->userId,
            'initialized_at' => '2026-11-21T10:00:00Z',
            'notes' => $notes,
        ];

        return $this->states[$holeId] + ['origin_id' => $this->originId];
    }

    public function recordProgress(TenantContext $context, string $holeId, array $report, string $correlationId): array
    {
        $this->calls[] = ['op' => 'progress.create@2', 'payload' => $report + ['hole_id' => $holeId]];

        foreach ($this->reports as $existing) {
            if ($existing['idempotency_key'] === $report['idempotency_key']) {
                if ($existing['hole_id'] === $holeId && $existing['payload_hash'] === $report['payload_hash']) {
                    return ['item' => $existing, 'replayed' => true, 'origin_id' => $this->originId];
                }
                throw new HttpException(409, GatewayHoleRecordsStore::CONFLICT_MESSAGE);
            }
        }

        $state = $this->states[$holeId] ?? null;
        if ($state === null) {
            throw ValidationException::withMessages(['depth_from' => 'La profundidad operativa del pozo no ha sido inicializada.']);
        }
        if (bccomp($report['depth_from'], $state['current_depth'], 2) !== 0) {
            throw ValidationException::withMessages(['depth_from' => 'La profundidad inicial debe coincidir exactamente con la profundidad operativa actual.']);
        }

        $item = $this->seedReport([
            'hole_id' => $holeId,
            'user_id' => (string) $context->userId,
            'depth_from' => $report['depth_from'],
            'depth_to' => $report['depth_to'],
            'depth_current' => $report['depth_to'],
            'shift' => $report['shift'],
            'hours_worked' => $report['hours_worked'] ?? null,
            'crew_snapshot' => $report['crew_snapshot'] ?? null,
            'observations' => $report['observations'] ?? null,
            'idempotency_key' => $report['idempotency_key'],
            'payload_hash' => $report['payload_hash'],
            'logged_at' => $report['logged_at'],
        ]);
        $this->states[$holeId]['current_depth'] = $report['depth_to'];

        return ['item' => $item, 'replayed' => false, 'origin_id' => $this->originId];
    }

    public function queryProgress(TenantContext $context, ?string $holeId, ?string $status, int $page, int $perPage, string $correlationId): array
    {
        $this->calls[] = ['op' => 'progress.list@2', 'payload' => compact('holeId', 'status', 'page', 'perPage')];
        $rows = array_values(array_filter($this->reports, fn ($r) => ($holeId === null || $r['hole_id'] === $holeId)
            && ($status === null || $r['status'] === $status)));
        usort($rows, fn ($a, $b) => strcmp($b['logged_at'], $a['logged_at']));

        $meters = '0.00';
        foreach ($rows as $r) {
            $meters = bcadd($meters, bcsub($r['depth_to'], $r['depth_from'], 2), 2);
        }

        return [
            'items' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total' => count($rows),
            'totals' => [
                'count' => count($rows),
                'total_meters' => $meters,
                'holes_count' => count(array_unique(array_column($rows, 'hole_id'))),
            ],
            'origin_id' => $this->originId,
        ];
    }

    public function getProgress(TenantContext $context, string $id, string $correlationId): array
    {
        $this->calls[] = ['op' => 'progress.get@2', 'payload' => ['id' => $id]];
        if (! isset($this->reports[$id])) {
            throw new GatewayException('NOT_FOUND', 'Connector reported an error', 404);
        }

        return $this->reports[$id] + ['origin_id' => $this->originId];
    }

    public function reviewProgress(TenantContext $context, string $id, string $action, ?string $notes, string $correlationId): array
    {
        $this->calls[] = ['op' => 'progress.review@1', 'payload' => compact('id', 'action', 'notes')];
        $report = $this->getProgress($context, $id, $correlationId);
        if ($report['status'] !== 'pending_review') {
            throw ValidationException::withMessages(['status' => 'Este parte diario ya fue revisado y no admite una nueva revisión.']);
        }
        $this->reports[$id]['status'] = $action === 'approve' ? 'approved' : 'rejected';
        $this->reports[$id]['reviewed_by'] = (string) $context->userId;
        $this->reports[$id]['reviewed_at'] = '2026-11-21T10:00:00Z';
        $this->reports[$id]['review_notes'] = $notes;

        return $this->reports[$id] + ['origin_id' => $this->originId];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function seedCoreLog(array $overrides = []): array
    {
        $run = array_merge([
            'id' => 'core_'.count($this->coreLogs),
            'hole_id' => 'POZO-01',
            'box_number' => null,
            'run_from' => '0.00',
            'run_to' => '3.00',
            'recovered_meters' => '2.80',
            'rqd_meters' => '2.50',
            'geology_notes' => null,
            'photo_url' => null,
            'logged_by' => '1',
            'logged_at' => '2026-11-10T12:00:00Z',
            'created_at' => '2026-11-10T12:00:00Z',
        ], $overrides);
        $this->coreLogs[$run['id']] = $this->withMetrics($run);

        return $this->coreLogs[$run['id']];
    }

    public function listCoreLogs(TenantContext $context, string $holeId, int $page, int $perPage, string $correlationId): array
    {
        $this->calls[] = ['op' => 'core_logs.list@1', 'payload' => compact('holeId', 'page', 'perPage')];
        $rows = array_values(array_filter($this->coreLogs, fn ($r) => $r['hole_id'] === $holeId));
        usort($rows, fn ($a, $b) => bccomp($b['run_from'], $a['run_from'], 2));

        $sum = fn (string $key) => array_reduce($rows, fn ($carry, $r) => bcadd($carry, $r[$key], 2), '0.00');
        $boxes = array_filter(array_map(fn ($r) => $r['box_number'] !== null ? trim($r['box_number']) : '', $rows));

        return [
            'items' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total' => count($rows),
            'totals' => [
                'total_runs' => count($rows),
                'total_drill_length' => $sum('drill_length'),
                'total_recovered_meters' => $sum('recovered_meters'),
                'total_rqd_meters' => $sum('rqd_meters'),
                'total_core_loss_meters' => $sum('core_loss_meters'),
                'total_boxes' => count(array_unique($boxes)),
            ],
            'origin_id' => $this->originId,
        ];
    }

    public function createCoreLog(TenantContext $context, string $holeId, array $run, string $correlationId): array
    {
        $this->calls[] = ['op' => 'core_logs.create@1', 'payload' => $run + ['hole_id' => $holeId]];

        return $this->seedCoreLog($run + ['hole_id' => $holeId, 'logged_by' => (string) $context->userId]) + ['origin_id' => $this->originId];
    }

    public function getCoreLog(TenantContext $context, string $holeId, string $id, string $correlationId): array
    {
        $this->calls[] = ['op' => 'core_logs.get@1', 'payload' => compact('holeId', 'id')];
        $run = $this->coreLogs[$id] ?? null;
        if ($run === null || $run['hole_id'] !== $holeId) {
            throw new GatewayException('NOT_FOUND', 'Connector reported an error', 404);
        }

        return $run + ['origin_id' => $this->originId];
    }

    public function updateCoreLog(TenantContext $context, string $holeId, string $id, array $changes, string $correlationId): array
    {
        $run = $this->getCoreLog($context, $holeId, $id, $correlationId);
        unset($run['origin_id']);
        $this->calls[] = ['op' => 'core_logs.update@1', 'payload' => $changes];

        foreach ($changes['text'] ?? [] as $key => $value) {
            $run[$key] = $value;
        }
        foreach (['recovered_meters', 'rqd_meters', 'logged_at'] as $key) {
            if (isset($changes[$key])) {
                $run[$key] = $changes[$key];
            }
        }
        $this->coreLogs[$id] = $this->withMetrics($run);

        return $this->coreLogs[$id] + ['origin_id' => $this->originId];
    }

    public function deleteCoreLog(TenantContext $context, string $holeId, string $id, string $correlationId): void
    {
        $this->getCoreLog($context, $holeId, $id, $correlationId);
        $this->calls[] = ['op' => 'core_logs.delete@1', 'payload' => compact('holeId', 'id')];
        unset($this->coreLogs[$id]);
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function withMetrics(array $run): array
    {
        $length = bcsub($run['run_to'], $run['run_from'], 2);
        if (bccomp($run['recovered_meters'], $length, 2) > 0) {
            throw ValidationException::withMessages(['recovered_meters' => 'Los metros recuperados no pueden ser mayores que el largo de la corrida.']);
        }
        if (bccomp($run['rqd_meters'], $run['recovered_meters'], 2) > 0) {
            throw ValidationException::withMessages(['rqd_meters' => 'Los metros de RQD no pueden superar los recuperados.']);
        }

        return array_merge($run, [
            'drill_length' => $length,
            'core_loss_meters' => bcsub($length, $run['recovered_meters'], 2),
            'rqd_percentage' => bcmul(bcdiv($run['rqd_meters'], $length, 4), '100', 2),
        ]);
    }

    public function crew(TenantContext $context, string $holeId, string $correlationId): array
    {
        $this->calls[] = ['op' => 'assignments.list@1', 'payload' => ['hole_id' => $holeId]];
        $history = array_values(array_filter($this->assignments, fn ($a) => $a['hole_id'] === $holeId));

        return [
            'active' => array_values(array_filter($history, fn ($a) => $a['active'])),
            'history' => array_reverse($history),
            'origin_id' => $this->originId,
        ];
    }

    public function assignCrew(TenantContext $context, string $holeId, string $mode, array $roles, array $extra, string $correlationId): array
    {
        $this->calls[] = ['op' => 'assignments.put@1', 'payload' => compact('holeId', 'mode', 'roles', 'extra')];

        $final = [];
        foreach ($this->assignments as $a) {
            if ($a['hole_id'] === $holeId && $a['active']) {
                $final[$a['role_in_hole']] = $a['user_id'];
            }
        }
        foreach ($roles as $role => $user) {
            if ($user === null) {
                unset($final[$role]);
            } else {
                $final[$role] = (string) $user;
            }
        }
        if (count($final) !== count(array_unique($final))) {
            throw ValidationException::withMessages([$mode === 'single' ? 'user_id' : 'crew' => 'Un usuario no puede tener multiples roles activos.']);
        }
        if ((isset($final['helper']) || isset($final['geologist'])) && ! isset($final['driller'])) {
            throw ValidationException::withMessages([$mode === 'single' ? 'user_id' : 'driller_id' => 'Debe asignar al menos un perforista para el equipo operativo.']);
        }

        foreach ($roles as $role => $user) {
            foreach ($this->assignments as $i => $a) {
                if ($a['hole_id'] === $holeId && $a['role_in_hole'] === $role && $a['active']) {
                    $this->assignments[$i]['active'] = false;
                }
            }
            if ($user !== null) {
                $this->assignments[] = [
                    'id' => 'asg_'.count($this->assignments),
                    'hole_id' => $holeId,
                    'user_id' => (string) $user,
                    'role_in_hole' => $role,
                    'assigned_by' => (string) $context->userId,
                    'assigned_from' => '2026-11-21T10:00:00Z',
                    'assigned_to' => null,
                    'active' => true,
                    'notes' => $extra['notes'] ?? null,
                    'created_at' => '2026-11-21T10:00:00Z',
                ];
            }
        }

        return [
            'active' => array_values(array_filter($this->assignments, fn ($a) => $a['hole_id'] === $holeId && $a['active'])),
            'origin_id' => $this->originId,
        ];
    }
}
