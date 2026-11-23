<?php

namespace App\Repositories\Gateway;

use App\Domain\Repositories\OrganizationStore;
use App\Services\Connector\CommandDispatcher;
use App\Support\GatewayException;
use App\Support\TenantContext;

/**
 * Projects, mines and hole links through the tenant's connector. Every call
 * dispatches one operation, reads the result and purges the command.
 */
class GatewayOrganizationStore implements OrganizationStore
{
    use RunsConnectorRecords;

    public function __construct(private readonly CommandDispatcher $dispatcher) {}

    public function listProjects(TenantContext $context, ?string $status, bool $includeMines, bool $includeHoles, string $correlationId): array
    {
        $result = $this->run($context, 'projects.list@1', array_filter([
            'status' => $status,
            'include_mines' => $includeMines,
            'include_holes' => $includeHoles,
        ]), $correlationId);

        return self::rows($result['items'] ?? null, $correlationId);
    }

    public function getProject(TenantContext $context, string $id, string $correlationId): ?array
    {
        return $this->orNull(fn () => $this->item($this->run($context, 'projects.get@1', ['id' => $id], $correlationId), $correlationId));
    }

    public function saveProject(TenantContext $context, ?string $id, array $fields, string $correlationId): ?array
    {
        return $this->orNull(fn () => $this->item($this->write($context, 'projects.upsert@1', $this->upsert($context, $id, $fields), $correlationId), $correlationId));
    }

    public function deleteProject(TenantContext $context, string $id, string $correlationId): bool
    {
        return $this->orNull(fn () => $this->write($context, 'projects.delete@1', ['id' => $id], $correlationId)) !== null;
    }

    public function listMines(TenantContext $context, ?string $projectId, ?string $status, bool $includeHoles, string $correlationId): array
    {
        $result = $this->run($context, 'mines.list@1', array_filter([
            'project_id' => $projectId,
            'status' => $status,
            'include_holes' => $includeHoles,
        ]), $correlationId);

        return self::rows($result['items'] ?? null, $correlationId);
    }

    public function getMine(TenantContext $context, string $id, string $correlationId): ?array
    {
        return $this->orNull(fn () => $this->item($this->run($context, 'mines.get@1', ['id' => $id], $correlationId), $correlationId));
    }

    public function saveMine(TenantContext $context, ?string $id, array $fields, string $correlationId): ?array
    {
        return $this->orNull(fn () => $this->item($this->write($context, 'mines.upsert@1', $this->upsert($context, $id, $fields), $correlationId), $correlationId));
    }

    public function deleteMine(TenantContext $context, string $id, string $correlationId): bool
    {
        return $this->orNull(fn () => $this->write($context, 'mines.delete@1', ['id' => $id], $correlationId)) !== null;
    }

    public function mineHoles(TenantContext $context, string $mineId, string $correlationId): ?array
    {
        return $this->orNull(fn () => self::rows(
            $this->run($context, 'mine_holes.list@1', ['id' => $mineId], $correlationId)['items'] ?? null,
            $correlationId,
        ));
    }

    public function attachHoles(TenantContext $context, string $mineId, array $holes, string $correlationId): ?array
    {
        $payload = [
            'mine_id' => $mineId,
            'user_id' => (string) $context->userId,
            'holes' => array_map(fn (array $h) => array_filter($h, fn ($v) => $v !== null && $v !== ''), $holes),
        ];

        return $this->orNull(fn () => self::rows(
            $this->write($context, 'mine_holes.attach@1', $payload, $correlationId)['items'] ?? null,
            $correlationId,
        ));
    }

    public function detachHole(TenantContext $context, string $mineId, string $holeId, string $correlationId): bool
    {
        return $this->orNull(fn () => $this->write($context, 'mine_holes.detach@1', [
            'mine_id' => $mineId,
            'hole_id' => $holeId,
        ], $correlationId)) !== null;
    }

    public function lookupHoles(TenantContext $context, array $holeIds, string $correlationId): array
    {
        if ($holeIds === []) {
            return [];
        }

        $result = $this->run($context, 'mine_holes.lookup@1', ['hole_ids' => array_values($holeIds)], $correlationId);

        $links = [];
        foreach (self::rows($result['items'] ?? null, $correlationId) as $row) {
            if (isset($row['hole_id'], $row['mine']['id'])) {
                $links[(string) $row['hole_id']] = ['mine' => $row['mine'], 'project' => $row['project'] ?? null];
            }
        }

        return $links;
    }

    /**
     * @param  array<string, string|null>  $fields
     * @return array<string, mixed>
     */
    private function upsert(TenantContext $context, ?string $id, array $fields): array
    {
        $payload = [
            // An empty map has to reach the connector as an object.
            'fields' => (object) $fields,
            'user_id' => (string) $context->userId,
        ];
        if ($id !== null) {
            $payload['id'] = $id;
        }

        return $payload;
    }

    /**
     * Runs $call and turns the connector's NOT_FOUND into null.
     *
     * @template T
     *
     * @param  \Closure(): T  $call
     * @return T|null
     */
    private function orNull(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (GatewayException $e) {
            if ($e->errorCode === 'NOT_FOUND') {
                return null;
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function item(array $result, string $correlationId): array
    {
        if (! is_array($result['item'] ?? null) || ! isset($result['item']['id'])) {
            throw new GatewayException('INVALID_RESULT', 'Connector returned an invalid result', 502, null, $correlationId);
        }

        return $result['item'];
    }
}
