<?php

namespace App\Repositories\Gateway;

use App\Domain\Repositories\PlanStore;
use App\Services\Connector\CommandDispatcher;
use App\Support\TenantContext;

/**
 * Plans, platforms and platform holes through the tenant's connector.
 * Every call dispatches one operation, reads the result and purges the
 * command (ADR-04).
 */
class GatewayPlanStore implements PlanStore
{
    use RunsConnectorRecords;

    public function __construct(private readonly CommandDispatcher $dispatcher) {}

    public function plans(TenantContext $context, ?string $mineId, ?string $status, string $correlationId): array
    {
        $result = $this->run($context, 'plans.list@1', array_filter([
            'mine_id' => $mineId,
            'status' => $status,
        ]), $correlationId);

        return ['items' => self::rows($result['items'] ?? null, $correlationId)];
    }

    public function plan(TenantContext $context, string $id, string $correlationId): ?array
    {
        return $this->nullWhenMissing(fn () => $this->itemOf($this->run($context, 'plans.get@1', ['id' => $id], $correlationId), $correlationId));
    }

    public function savePlan(TenantContext $context, ?string $id, array $fields, string $correlationId): ?array
    {
        return $this->nullWhenMissing(fn () => $this->itemOf($this->write($context, 'plans.upsert@1', $this->upsert($context, $id, $fields), $correlationId), $correlationId));
    }

    public function deletePlan(TenantContext $context, string $id, string $correlationId): bool
    {
        return $this->nullWhenMissing(fn () => $this->write($context, 'plans.delete@1', ['id' => $id], $correlationId)) !== null;
    }

    public function platform(TenantContext $context, string $id, string $correlationId): ?array
    {
        return $this->nullWhenMissing(fn () => $this->itemOf($this->run($context, 'platforms.get@1', ['id' => $id], $correlationId), $correlationId));
    }

    public function savePlatform(TenantContext $context, ?string $id, array $fields, string $correlationId): ?array
    {
        return $this->nullWhenMissing(fn () => $this->itemOf($this->write($context, 'platforms.upsert@1', $this->upsert($context, $id, $fields), $correlationId), $correlationId));
    }

    public function deletePlatform(TenantContext $context, string $id, string $correlationId): bool
    {
        return $this->nullWhenMissing(fn () => $this->write($context, 'platforms.delete@1', ['id' => $id], $correlationId)) !== null;
    }

    public function attachHoles(TenantContext $context, string $platformId, array $holes, string $correlationId): ?array
    {
        $payload = [
            'platform_id' => $platformId,
            'user_id' => (string) $context->userId,
            'holes' => array_map(fn (array $h) => array_filter($h, fn ($v) => $v !== null && $v !== ''), $holes),
        ];

        return $this->nullWhenMissing(fn () => $this->itemOf($this->write($context, 'platform_holes.attach@1', $payload, $correlationId), $correlationId));
    }

    public function detachHole(TenantContext $context, string $platformId, string $holeId, string $correlationId): bool
    {
        return $this->nullWhenMissing(fn () => $this->write($context, 'platform_holes.detach@1', [
            'platform_id' => $platformId,
            'hole_id' => $holeId,
        ], $correlationId)) !== null;
    }

    /**
     * @param  array<string, string|null>  $fields
     * @return array<string, mixed>
     */
    private function upsert(TenantContext $context, ?string $id, array $fields): array
    {
        $payload = ['fields' => (object) $fields, 'user_id' => (string) $context->userId];
        if ($id !== null) {
            $payload['id'] = $id;
        }

        return $payload;
    }
}
