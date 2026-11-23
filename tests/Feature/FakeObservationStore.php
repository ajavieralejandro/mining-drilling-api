<?php

namespace Tests\Feature;

use App\Domain\Repositories\ObservationStore;
use App\Repositories\Gateway\GatewayHoleRecordsStore;
use App\Support\TenantContext;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * In-memory stand-in for the observation tables of a client database,
 * mirroring the connector's rules: active type, depth not below the drilled
 * one, idempotent create.
 */
class FakeObservationStore implements ObservationStore
{
    /** @var array<string, array<string, mixed>> */
    public array $types = [
        'obt_operational' => ['id' => 'obt_operational', 'code' => 'operational', 'name' => 'Operativa', 'active' => true, 'sort_order' => 10],
        'obt_geology' => ['id' => 'obt_geology', 'code' => 'geology', 'name' => 'Geología', 'active' => true, 'sort_order' => 20],
        'obt_safety' => ['id' => 'obt_safety', 'code' => 'safety', 'name' => 'Seguridad', 'active' => true, 'sort_order' => 30],
        'obt_general' => ['id' => 'obt_general', 'code' => 'general', 'name' => 'General', 'active' => true, 'sort_order' => 40],
    ];

    /** @var list<array<string, mixed>> */
    public array $observations = [];

    /** @var array<string, string> hole id => drilled depth */
    public array $currentDepth = [];

    /** @var array<string, array<string, string|null>> hole id => geometry columns present */
    public array $geometry = [];

    /** @var array<string, list<array<string, mixed>>> hole id => unresolved risks with depth */
    public array $risks = [];

    /** @var list<string> */
    public array $calls = [];

    public function types(TenantContext $context, bool $includeInactive, string $correlationId): array
    {
        $this->calls[] = 'observation_types.list@1';
        $rows = array_values(array_filter($this->types, fn ($t) => $includeInactive || $t['active']));
        usort($rows, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        return $rows;
    }

    public function saveType(TenantContext $context, ?string $id, array $fields, string $correlationId): ?array
    {
        $this->calls[] = 'observation_types.upsert@1';
        if ($id !== null && ! isset($this->types[$id])) {
            return null;
        }
        $type = $id !== null ? $this->types[$id] : ['id' => 'obt_'.count($this->types), 'active' => true, 'sort_order' => 0];
        foreach ($fields as $k => $v) {
            $type[$k] = match ($k) {
                'active' => $v === 'true',
                'sort_order' => (int) $v,
                'code' => strtolower((string) $v),
                default => $v,
            };
        }
        foreach ($this->types as $other) {
            if ($other['id'] !== $type['id'] && $other['code'] === $type['code']) {
                throw ValidationException::withMessages(['code' => 'Ya existe un tipo de observación con ese código.']);
            }
        }
        $this->types[$type['id']] = $type;

        return $type;
    }

    public function observations(TenantContext $context, string $holeId, ?string $typeId, int $page, int $perPage, string $correlationId): array
    {
        $this->calls[] = 'observations.list@1';
        $rows = array_values(array_filter(
            array_reverse($this->observations),
            fn ($o) => $o['hole_id'] === $holeId && ($typeId === null || $o['type']['id'] === $typeId),
        ));

        return ['items' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'total' => count($rows)];
    }

    public function record(TenantContext $context, string $holeId, string $typeId, string $body, ?string $depth, string $idempotencyKey, string $correlationId): array
    {
        $this->calls[] = 'observations.create@1';
        foreach ($this->observations as $o) {
            if ($o['idempotency_key'] === $idempotencyKey) {
                if ($o['hole_id'] !== $holeId || $o['type']['id'] !== $typeId || $o['body'] !== $body) {
                    throw new HttpException(409, GatewayHoleRecordsStore::CONFLICT_MESSAGE);
                }

                return ['item' => $o, 'replayed' => true];
            }
        }
        $type = $this->types[$typeId] ?? null;
        if ($type === null || ! $type['active']) {
            throw ValidationException::withMessages(['type_id' => 'El tipo de observación no existe o está desactivado.']);
        }
        $current = $this->currentDepth[$holeId] ?? null;
        if ($depth !== null && $current !== null && (float) $depth > (float) $current) {
            throw ValidationException::withMessages(['depth' => "La profundidad no puede superar la profundidad actual del pozo ({$current} m)."]);
        }
        $item = [
            'id' => 'obs_'.count($this->observations),
            'hole_id' => $holeId,
            'type' => ['id' => $type['id'], 'code' => $type['code'], 'name' => $type['name']],
            'body' => $body,
            'depth' => $depth !== null ? number_format((float) $depth, 2, '.', '') : null,
            'user_id' => (string) $context->userId,
            'created_at' => '2026-11-22T10:00:00Z',
            'idempotency_key' => $idempotencyKey,
        ];
        $this->observations[] = $item;

        return ['item' => $item, 'replayed' => false];
    }

    public function geometry(TenantContext $context, string $holeId, string $correlationId): array
    {
        $this->calls[] = 'hole_geometry.get@1';
        $values = $this->geometry[$holeId] ?? [];
        $columns = ['planned_depth', 'azimuth', 'dip', 'easting', 'northing', 'elevation'];

        return [
            'values' => $values,
            'missing' => array_values(array_diff($columns, array_keys($values))),
            'current_depth' => $this->currentDepth[$holeId] ?? null,
            'marks' => array_values(array_filter($this->observations, fn ($o) => $o['hole_id'] === $holeId && $o['depth'] !== null)),
            'risks' => $this->risks[$holeId] ?? [],
        ];
    }
}
