<?php

namespace App\Repositories\Gateway;

use App\Domain\Repositories\RiskStore;
use App\Services\Connector\CommandDispatcher;
use App\Support\TenantContext;

/**
 * Risks through the tenant's connector. Every call dispatches one
 * operation, reads the result and purges the command (ADR-04).
 */
class GatewayRiskStore implements RiskStore
{
    use RunsConnectorRecords;

    public function __construct(private readonly CommandDispatcher $dispatcher) {}

    public function risks(TenantContext $context, ?string $holeId, array $statuses, array $levels, int $page, int $perPage, string $correlationId): array
    {
        $result = $this->run($context, 'risks.list@1', array_filter([
            'hole_id' => $holeId,
            'statuses' => array_values($statuses),
            'levels' => array_values($levels),
            'page' => $page,
            'per_page' => $perPage,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []), $correlationId);

        $items = self::rows($result['items'] ?? null, $correlationId);
        $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];

        return [
            'items' => $items,
            'total' => (int) ($result['total'] ?? count($items)),
            'counts' => array_map('intval', array_merge([
                'open' => 0, 'mitigated' => 0, 'closed' => 0, 'open_critical' => 0, 'open_high' => 0, 'holes_with_open' => 0,
            ], $counts)),
        ];
    }

    public function report(TenantContext $context, string $holeId, array $risk, string $idempotencyKey, string $correlationId): array
    {
        $result = $this->write($context, 'risks.create@1', array_filter($risk + [
            'hole_id' => $holeId,
            'user_id' => (string) $context->userId,
            'idempotency_key' => $idempotencyKey,
        ], fn ($v) => $v !== null && $v !== ''), $correlationId);

        return [
            'item' => $this->itemOf($result, $correlationId),
            'replayed' => ($result['outcome'] ?? null) === 'replayed',
        ];
    }

    public function transition(TenantContext $context, string $riskId, string $to, ?string $notes, string $correlationId): ?array
    {
        return $this->nullWhenMissing(fn () => $this->itemOf($this->write($context, 'risks.transition@1', array_filter([
            'id' => $riskId,
            'to' => $to,
            'notes' => $notes,
            'user_id' => (string) $context->userId,
        ], fn ($v) => $v !== null && $v !== ''), $correlationId), $correlationId));
    }
}
