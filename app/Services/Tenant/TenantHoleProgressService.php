<?php

namespace App\Services\Tenant;

use App\Domain\Repositories\HoleRecordsStore;
use App\Support\TenantContext;
use Carbon\Carbon;

class TenantHoleProgressService
{
    public function __construct(
        private readonly HoleRecordsStore $records,
    ) {}

    /**
     * Partes diarios del pozo, leídos de la base de la minera por el conector.
     *
     * @return array{items: list<array<string, mixed>>, total: int, totals: array<string, mixed>, origin_id: ?string}
     */
    public function listProgress(
        TenantContext $context,
        string $holeId,
        int $perPage,
        int $page,
        ?string $status,
        string $correlationId,
    ): array {
        $status = $status !== null && trim($status) !== '' ? trim($status) : null;

        return $this->records->queryProgress($context, $holeId, $status, $page, $perPage, $correlationId);
    }

    /**
     * @return array<string, mixed>
     */
    public function getProgress(TenantContext $context, string $progressId, string $correlationId): array
    {
        return $this->records->getProgress($context, $progressId, $correlationId);
    }

    /**
     * Bandeja del supervisor: partes pendientes de revisión y su resumen.
     *
     * @return array{items: list<array<string, mixed>>, total: int, summary: array<string, mixed>, origin_id: ?string}
     */
    public function listPendingProgress(
        TenantContext $context,
        ?string $holeId,
        int $perPage,
        int $page,
        string $correlationId,
    ): array {
        $holeId = $holeId !== null && trim($holeId) !== '' ? trim($holeId) : null;
        $result = $this->records->queryProgress($context, $holeId, 'pending_review', $page, $perPage, $correlationId);

        return [
            'items' => $result['items'],
            'total' => $result['total'],
            'summary' => [
                'total_pending' => $result['totals']['count'],
                'total_pending_meters' => sprintf('%.2f', $result['totals']['total_meters']),
                'holes_count' => $result['totals']['holes_count'],
            ],
            'origin_id' => $result['origin_id'],
        ];
    }

    /**
     * Aprueba o rechaza un parte. La regla "solo se revisa una vez" se aplica
     * en la transacción de la base de la minera.
     *
     * @return array<string, mixed>
     */
    public function reviewProgress(
        TenantContext $context,
        string $progressId,
        string $action,
        ?string $notes,
        string $correlationId,
    ): array {
        return $this->records->reviewProgress($context, $progressId, $action, $notes, $correlationId);
    }

    /**
     * Registra un tramo de avance en la base de la minera. La API normaliza y
     * calcula el hash de idempotencia; continuidad de cotas, turno, tope de
     * profundidad planificada e idempotencia se validan en el conector, dentro
     * de la transacción que bloquea el estado del pozo.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $gatewayHoleItem
     * @return array{item: array<string, mixed>, replayed: bool, origin_id: ?string}
     */
    public function recordProgress(
        TenantContext $context,
        string $originId,
        string $holeId,
        array $data,
        ?array $gatewayHoleItem,
        array $crewSnapshot,
        string $correlationId,
    ): array {
        $depthFrom = sprintf('%.2f', $data['depth_from']);
        $depthTo = sprintf('%.2f', $data['depth_to']);
        $hoursWorked = isset($data['hours_worked']) ? sprintf('%.2f', $data['hours_worked']) : null;
        $shift = isset($data['shift']) && is_string($data['shift']) ? strtoupper(trim($data['shift'])) : null;
        $loggedAt = isset($data['logged_at']) ? Carbon::parse($data['logged_at'])->toISOString() : now()->toISOString();
        $observations = isset($data['observations']) && is_string($data['observations']) && trim($data['observations']) !== ''
            ? trim($data['observations'])
            : null;

        $payloadHash = hash('sha256', json_encode([
            'depth_from' => $depthFrom,
            'depth_to' => $depthTo,
            'hours_worked' => $hoursWorked,
            'shift' => $shift,
            'logged_at' => $loggedAt,
            'observations' => $observations,
        ], JSON_THROW_ON_ERROR));

        $initialDepth = isset($data['initial_depth']) && in_array($context->role, ['admin', 'supervisor'], true)
            ? sprintf('%.2f', $data['initial_depth'])
            : null;

        return $this->records->recordProgress($context, $holeId, [
            'depth_from' => $depthFrom,
            'depth_to' => $depthTo,
            'hours_worked' => $hoursWorked,
            'shift' => $shift,
            'logged_at' => $loggedAt,
            'observations' => $observations,
            'idempotency_key' => trim((string) $data['idempotency_key']),
            'payload_hash' => $payloadHash,
            'crew_snapshot' => $crewSnapshot,
            'planned_depth' => self::depthOrNull($gatewayHoleItem['planned_depth'] ?? null),
            'reached_depth' => self::depthOrNull($gatewayHoleItem['reached_depth'] ?? null),
            'initial_depth' => $initialDepth,
        ], $correlationId);
    }

    private static function depthOrNull(mixed $value): ?string
    {
        return is_numeric($value) ? sprintf('%.2f', $value) : null;
    }

    /**
     * Loguera del pozo, leída de la base de la minera.
     *
     * @return array{items: list<array<string, mixed>>, total: int, totals: array<string, mixed>, origin_id: ?string}
     */
    public function listCoreLogs(TenantContext $context, string $holeId, int $perPage, int $page, string $correlationId): array
    {
        return $this->records->listCoreLogs($context, $holeId, $page, $perPage, $correlationId);
    }

    /**
     * Asienta una corrida. Largo, pérdida y RQD % los calcula el conector,
     * que también rechaza recuperado > largo o RQD > recuperado.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function recordCoreLog(TenantContext $context, string $holeId, array $data, string $correlationId): array
    {
        return $this->records->createCoreLog($context, $holeId, [
            'run_from' => sprintf('%.2f', $data['run_from']),
            'run_to' => sprintf('%.2f', $data['run_to']),
            'recovered_meters' => sprintf('%.2f', $data['recovered_meters']),
            'rqd_meters' => sprintf('%.2f', $data['rqd_meters']),
            'logged_at' => isset($data['logged_at']) ? Carbon::parse($data['logged_at'])->toISOString() : now()->toISOString(),
            'box_number' => self::text($data['box_number'] ?? null),
            'geology_notes' => self::text($data['geology_notes'] ?? null),
            'photo_url' => self::text($data['photo_url'] ?? null),
        ], $correlationId);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCoreLog(TenantContext $context, string $holeId, string $coreLogId, string $correlationId): array
    {
        return $this->records->getCoreLog($context, $holeId, $coreLogId, $correlationId);
    }

    /**
     * Cambia solo lo que vino en el pedido. Si cambian recuperado o RQD, el
     * conector recalcula las métricas con el largo guardado de la corrida.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateCoreLog(TenantContext $context, string $holeId, string $coreLogId, array $data, string $correlationId): array
    {
        $changes = [];
        $text = [];
        foreach (['box_number', 'geology_notes', 'photo_url'] as $key) {
            if (array_key_exists($key, $data)) {
                $text[$key] = self::text($data[$key]);
            }
        }
        if ($text !== []) {
            $changes['text'] = $text;
        }
        foreach (['recovered_meters', 'rqd_meters'] as $key) {
            if (isset($data[$key])) {
                $changes[$key] = sprintf('%.2f', $data[$key]);
            }
        }
        if (isset($data['logged_at'])) {
            $changes['logged_at'] = Carbon::parse($data['logged_at'])->toISOString();
        }

        return $this->records->updateCoreLog($context, $holeId, $coreLogId, $changes, $correlationId);
    }

    public function deleteCoreLog(TenantContext $context, string $holeId, string $coreLogId, string $correlationId): void
    {
        $this->records->deleteCoreLog($context, $holeId, $coreLogId, $correlationId);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
