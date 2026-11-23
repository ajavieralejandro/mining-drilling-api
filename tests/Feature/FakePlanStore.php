<?php

namespace Tests\Feature;

use App\Domain\Repositories\PlanStore;
use App\Support\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * In-memory stand-in for the plan tables of a client database, mirroring
 * the connector's rules: free codes, existing plan, existing hole, cascade
 * on delete.
 */
class FakePlanStore implements PlanStore
{
    /** @var array<string, array<string, mixed>> */
    public array $plans = [];

    /** @var array<string, array<string, mixed>> */
    public array $platforms = [];

    /** @var array<string, array<string, mixed>> hole id => link */
    public array $links = [];

    /** @var list<string> hole ids missing from the client's hole table */
    public array $missingHoles = [];

    /** @var list<string> */
    public array $calls = [];

    private int $seq = 0;

    public function plans(TenantContext $context, ?string $mineId, ?string $status, string $correlationId): array
    {
        $this->calls[] = 'plans.list@1';
        $rows = array_filter($this->plans, fn ($p) => ($mineId === null || $p['mine_id'] === $mineId) && ($status === null || $p['status'] === $status));

        return ['items' => array_values(array_map(fn ($p) => $this->planRow($p), $rows))];
    }

    public function plan(TenantContext $context, string $id, string $correlationId): ?array
    {
        $this->calls[] = 'plans.get@1';
        if (! isset($this->plans[$id])) {
            return null;
        }

        return $this->planRow($this->plans[$id]) + [
            'platforms' => array_values(array_map(fn ($f) => $this->platformRow($f, false), array_filter($this->platforms, fn ($f) => $f['plan_id'] === $id))),
        ];
    }

    public function savePlan(TenantContext $context, ?string $id, array $fields, string $correlationId): ?array
    {
        $this->calls[] = 'plans.upsert@1';
        if ($id !== null && ! isset($this->plans[$id])) {
            return null;
        }
        $row = array_merge($id !== null ? $this->plans[$id] : ['id' => 'pln_'.(++$this->seq), 'code' => null, 'mine_id' => null, 'status' => 'planned', 'planned_meters' => null, 'created_by' => (string) $context->userId], $fields);
        if (isset($row['code'])) {
            $row['code'] = strtoupper($row['code']);
        }
        $this->assertCodeFree($this->plans, $row, 'Ya existe un plan con ese código en la organización.');
        $this->plans[$row['id']] = $row;

        return $this->planRow($row);
    }

    public function deletePlan(TenantContext $context, string $id, string $correlationId): bool
    {
        $this->calls[] = 'plans.delete@1';
        if (! isset($this->plans[$id])) {
            return false;
        }
        foreach ($this->platforms as $pid => $f) {
            if ($f['plan_id'] === $id) {
                $this->dropPlatform($pid);
            }
        }
        unset($this->plans[$id]);

        return true;
    }

    public function platform(TenantContext $context, string $id, string $correlationId): ?array
    {
        $this->calls[] = 'platforms.get@1';

        return isset($this->platforms[$id]) ? $this->platformRow($this->platforms[$id], true) : null;
    }

    public function savePlatform(TenantContext $context, ?string $id, array $fields, string $correlationId): ?array
    {
        $this->calls[] = 'platforms.upsert@1';
        if ($id !== null && ! isset($this->platforms[$id])) {
            return null;
        }
        $row = array_merge($id !== null ? $this->platforms[$id] : ['id' => 'plt_'.(++$this->seq), 'status' => 'planned', 'easting' => null, 'northing' => null], $fields);
        if (! isset($this->plans[$row['plan_id'] ?? ''])) {
            throw ValidationException::withMessages(['plan_id' => 'El plan indicado no existe en la organización.']);
        }
        $row['code'] = strtoupper($row['code']);
        $this->assertCodeFree($this->platforms, $row, 'Ya existe una plataforma con ese código en la organización.');
        $this->platforms[$row['id']] = $row;

        return $this->platformRow($row, false);
    }

    public function deletePlatform(TenantContext $context, string $id, string $correlationId): bool
    {
        $this->calls[] = 'platforms.delete@1';
        if (! isset($this->platforms[$id])) {
            return false;
        }
        $this->dropPlatform($id);

        return true;
    }

    public function attachHoles(TenantContext $context, string $platformId, array $holes, string $correlationId): ?array
    {
        $this->calls[] = 'platform_holes.attach@1';
        if (! isset($this->platforms[$platformId])) {
            return null;
        }
        foreach ($holes as $i => $h) {
            if (in_array($h['hole_id'], $this->missingHoles, true)) {
                throw ValidationException::withMessages([count($holes) > 1 ? "holes.{$i}.hole_id" : 'hole_id' => 'El pozo indicado no existe en la base de origen.']);
            }
        }
        foreach ($holes as $h) {
            $this->links[$h['hole_id']] = ['platform_id' => $platformId, 'sequence' => $h['sequence'] ?? null, 'target' => $h['target'] ?? null];
        }

        return $this->platformRow($this->platforms[$platformId], true);
    }

    public function detachHole(TenantContext $context, string $platformId, string $holeId, string $correlationId): bool
    {
        $this->calls[] = 'platform_holes.detach@1';
        if (($this->links[$holeId]['platform_id'] ?? null) !== $platformId) {
            return false;
        }
        unset($this->links[$holeId]);

        return true;
    }

    private function dropPlatform(string $id): void
    {
        foreach ($this->links as $hole => $l) {
            if ($l['platform_id'] === $id) {
                unset($this->links[$hole]);
            }
        }
        unset($this->platforms[$id]);
    }

    private function assertCodeFree(array $rows, array $row, string $message): void
    {
        foreach ($rows as $other) {
            if ($other['id'] !== $row['id'] && ($row['code'] ?? null) !== null && ($other['code'] ?? null) === $row['code']) {
                throw ValidationException::withMessages(['code' => $message]);
            }
        }
    }

    private function holesOf(string $platformId): array
    {
        $out = [];
        foreach ($this->links as $hole => $l) {
            if ($l['platform_id'] === $platformId) {
                $out[] = ['hole_id' => (string) $hole, 'code' => 'H-'.$hole, 'status' => 'in_progress', 'sequence' => $l['sequence'], 'target' => $l['target'],
                    'current_depth' => null, 'geometry' => ['azimuth' => '45.00', 'dip' => '-60.00']];
            }
        }

        return $out;
    }

    private function platformRow(array $f, bool $withHoles): array
    {
        $row = $f + ['holes_count' => count($this->holesOf($f['id']))];
        if ($withHoles) {
            $row['holes'] = $this->holesOf($f['id']);
        }

        return $row;
    }

    private function planRow(array $p): array
    {
        $platformIds = array_keys(array_filter($this->platforms, fn ($f) => $f['plan_id'] === $p['id']));

        return $p + [
            'executed_meters' => '0.00',
            'platforms_count' => count($platformIds),
            'holes_count' => count(array_filter($this->links, fn ($l) => in_array($l['platform_id'], $platformIds, true))),
        ];
    }
}
