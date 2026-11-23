<?php

namespace Tests\Feature;

use App\Domain\Repositories\OrganizationStore;
use App\Support\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * In-memory stand-in for the client databases behind each tenant's
 * connector. Data is kept per tenant, like one database per company, and
 * the connector's rules (free code, existing project, existing hole,
 * cascade on delete) are mirrored so the HTTP flow can be tested without a
 * connector.
 */
class FakeOrganizationStore implements OrganizationStore
{
    /** @var array<string, array{projects: array<string, array<string, mixed>>, mines: array<string, array<string, mixed>>, links: array<string, array<string, mixed>>}> */
    public array $db = [];

    /** @var list<string> hole ids that do not exist in the client's hole table */
    public array $missingHoles = [];

    /** @var list<array{op: string, tenant: string}> */
    public array $calls = [];

    private int $seq = 0;

    private function &tenant(TenantContext $context, string $op): array
    {
        $this->calls[] = ['op' => $op, 'tenant' => $context->tenantId];
        $this->db[$context->tenantId] ??= ['projects' => [], 'mines' => [], 'links' => []];

        return $this->db[$context->tenantId];
    }

    public function listProjects(TenantContext $context, ?string $status, bool $includeMines, bool $includeHoles, string $correlationId): array
    {
        $t = &$this->tenant($context, 'projects.list@1');
        $rows = array_values(array_filter($t['projects'], fn ($p) => $status === null || $p['status'] === $status));
        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return array_map(fn ($p) => $this->projectRow($t, $p, $includeMines || $includeHoles, $includeHoles), $rows);
    }

    public function getProject(TenantContext $context, string $id, string $correlationId): ?array
    {
        $t = &$this->tenant($context, 'projects.get@1');

        return isset($t['projects'][$id]) ? $this->projectRow($t, $t['projects'][$id], true, true) : null;
    }

    public function saveProject(TenantContext $context, ?string $id, array $fields, string $correlationId): ?array
    {
        $t = &$this->tenant($context, 'projects.upsert@1');
        if ($id !== null && ! isset($t['projects'][$id])) {
            return null;
        }
        $row = array_merge($id !== null ? $t['projects'][$id] : ['id' => 'prj_'.(++$this->seq), 'code' => null, 'description' => null, 'status' => 'active', 'created_by' => (string) $context->userId], $fields);
        $this->assertCodeFree($t['projects'], $row, 'Ya existe un proyecto con ese código en la organización.');
        $t['projects'][$row['id']] = $row;

        return $this->projectRow($t, $row, false, false);
    }

    public function deleteProject(TenantContext $context, string $id, string $correlationId): bool
    {
        $t = &$this->tenant($context, 'projects.delete@1');
        if (! isset($t['projects'][$id])) {
            return false;
        }
        foreach ($t['mines'] as $mineId => $mine) {
            if ($mine['project_id'] === $id) {
                $this->dropMine($t, $mineId);
            }
        }
        unset($t['projects'][$id]);

        return true;
    }

    public function listMines(TenantContext $context, ?string $projectId, ?string $status, bool $includeHoles, string $correlationId): array
    {
        $t = &$this->tenant($context, 'mines.list@1');
        $rows = array_values(array_filter($t['mines'], fn ($m) => ($projectId === null || $m['project_id'] === $projectId) && ($status === null || $m['status'] === $status)));

        return array_map(fn ($m) => $this->mineRow($t, $m, $includeHoles), $rows);
    }

    public function getMine(TenantContext $context, string $id, string $correlationId): ?array
    {
        $t = &$this->tenant($context, 'mines.get@1');

        return isset($t['mines'][$id]) ? $this->mineRow($t, $t['mines'][$id], true) : null;
    }

    public function saveMine(TenantContext $context, ?string $id, array $fields, string $correlationId): ?array
    {
        $t = &$this->tenant($context, 'mines.upsert@1');
        if ($id !== null && ! isset($t['mines'][$id])) {
            return null;
        }
        $row = array_merge($id !== null ? $t['mines'][$id] : ['id' => 'min_'.(++$this->seq), 'code' => null, 'location' => null, 'description' => null, 'status' => 'active', 'created_by' => (string) $context->userId], $fields);
        if (! isset($t['projects'][$row['project_id'] ?? ''])) {
            throw ValidationException::withMessages(['project_id' => 'El proyecto indicado no pertenece a esta organización o no existe.']);
        }
        $this->assertCodeFree($t['mines'], $row, 'Ya existe una mina con ese código en la organización.');
        $t['mines'][$row['id']] = $row;

        return $this->mineRow($t, $row, false);
    }

    public function deleteMine(TenantContext $context, string $id, string $correlationId): bool
    {
        $t = &$this->tenant($context, 'mines.delete@1');
        if (! isset($t['mines'][$id])) {
            return false;
        }
        $this->dropMine($t, $id);

        return true;
    }

    public function mineHoles(TenantContext $context, string $mineId, string $correlationId): ?array
    {
        $t = &$this->tenant($context, 'mine_holes.list@1');

        return isset($t['mines'][$mineId]) ? $this->linksOf($t, $mineId) : null;
    }

    public function attachHoles(TenantContext $context, string $mineId, array $holes, string $correlationId): ?array
    {
        $t = &$this->tenant($context, 'mine_holes.attach@1');
        if (! isset($t['mines'][$mineId])) {
            return null;
        }
        foreach ($holes as $i => $h) {
            if (in_array($h['hole_id'], $this->missingHoles, true)) {
                $field = count($holes) > 1 ? "holes.{$i}.hole_id" : 'hole_id';
                throw ValidationException::withMessages([$field => 'El pozo indicado no existe en la base de origen.']);
            }
        }
        $out = [];
        foreach ($holes as $h) {
            $created = ! isset($t['links'][$h['hole_id']]);
            $t['links'][$h['hole_id']] = [
                'hole_id' => $h['hole_id'],
                'mine_id' => $mineId,
                'hole_code' => 'H-'.$h['hole_id'],
                'origin_id' => $h['origin_id'] ?? null,
                'status' => $h['status'] ?? 'in_progress',
                'notes' => $h['notes'] ?? null,
                'assigned_by' => (string) $context->userId,
            ];
            $out[] = $this->linkRow($t, $t['links'][$h['hole_id']]) + ['created' => $created];
        }

        return $out;
    }

    public function detachHole(TenantContext $context, string $mineId, string $holeId, string $correlationId): bool
    {
        $t = &$this->tenant($context, 'mine_holes.detach@1');
        if (($t['links'][$holeId]['mine_id'] ?? null) !== $mineId) {
            return false;
        }
        unset($t['links'][$holeId]);

        return true;
    }

    public function lookupHoles(TenantContext $context, array $holeIds, string $correlationId): array
    {
        $t = &$this->tenant($context, 'mine_holes.lookup@1');
        $out = [];
        foreach ($holeIds as $id) {
            if (isset($t['links'][$id])) {
                $mine = $t['mines'][$t['links'][$id]['mine_id']];
                $project = $t['projects'][$mine['project_id']];
                $out[$id] = [
                    'mine' => ['id' => $mine['id'], 'project_id' => $mine['project_id'], 'code' => $mine['code'], 'name' => $mine['name'], 'location' => $mine['location'], 'status' => $mine['status']],
                    'project' => ['id' => $project['id'], 'code' => $project['code'], 'name' => $project['name'], 'status' => $project['status']],
                ];
            }
        }

        return $out;
    }

    // ---- helpers ---------------------------------------------------------

    private function assertCodeFree(array $rows, array $row, string $message): void
    {
        if (($row['code'] ?? null) === null) {
            return;
        }
        foreach ($rows as $other) {
            if ($other['id'] !== $row['id'] && $other['code'] === $row['code']) {
                throw ValidationException::withMessages(['code' => $message]);
            }
        }
    }

    private function dropMine(array &$t, string $mineId): void
    {
        foreach ($t['links'] as $holeId => $link) {
            if ($link['mine_id'] === $mineId) {
                unset($t['links'][$holeId]);
            }
        }
        unset($t['mines'][$mineId]);
    }

    private function linksOf(array $t, string $mineId): array
    {
        $links = array_values(array_filter($t['links'], fn ($l) => $l['mine_id'] === $mineId));

        return array_map(fn ($l) => $this->linkRow($t, $l), $links);
    }

    private function linkRow(array $t, array $link): array
    {
        $mine = $t['mines'][$link['mine_id']];

        return $link + [
            'created_at' => '2026-11-21T10:00:00Z',
            'updated_at' => '2026-11-21T10:00:00Z',
            'mine' => ['id' => $mine['id'], 'project_id' => $mine['project_id'], 'code' => $mine['code'], 'name' => $mine['name'], 'location' => $mine['location'], 'status' => $mine['status']],
        ];
    }

    private function mineRow(array $t, array $mine, bool $withHoles): array
    {
        $project = $t['projects'][$mine['project_id']];
        $links = $this->linksOf($t, $mine['id']);
        $row = $mine + [
            'holes_count' => count($links),
            'project' => ['id' => $project['id'], 'code' => $project['code'], 'name' => $project['name'], 'status' => $project['status']],
            'created_at' => '2026-11-21T10:00:00Z',
            'updated_at' => '2026-11-21T10:00:00Z',
        ];
        if ($withHoles) {
            $row['holes'] = $links;
        }

        return $row;
    }

    private function projectRow(array $t, array $project, bool $withMines, bool $withHoles): array
    {
        $mines = array_values(array_filter($t['mines'], fn ($m) => $m['project_id'] === $project['id']));
        $row = $project + [
            'mines_count' => count($mines),
            'holes_count' => array_sum(array_map(fn ($m) => count($this->linksOf($t, $m['id'])), $mines)),
            'created_at' => '2026-11-21T10:00:00Z',
            'updated_at' => '2026-11-21T10:00:00Z',
        ];
        if ($withMines) {
            $row['mines'] = array_map(fn ($m) => $this->mineRow($t, $m, $withHoles), $mines);
        }

        return $row;
    }
}
