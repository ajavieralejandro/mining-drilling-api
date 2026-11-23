<?php

namespace Tests\Feature;

use App\Domain\Repositories\RiskStore;
use App\Support\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * In-memory stand-in for the risk table of a client database, mirroring the
 * connector's rules: depth not below the drilled one, lifecycle
 * open → mitigated → closed, idempotent report.
 */
class FakeRiskStore implements RiskStore
{
    /** @var list<array<string, mixed>> */
    public array $risks = [];

    /** @var array<string, string> hole id => drilled depth */
    public array $currentDepth = [];

    /** @var list<string> */
    public array $calls = [];

    public function risks(TenantContext $context, ?string $holeId, array $statuses, array $levels, int $page, int $perPage, string $correlationId): array
    {
        $this->calls[] = 'risks.list@1';
        $scoped = array_values(array_filter($this->risks, fn ($r) => $holeId === null || $r['hole_id'] === $holeId));
        $open = fn ($r) => $r['status'] !== 'closed';
        $counts = [
            'open' => count(array_filter($scoped, fn ($r) => $r['status'] === 'open')),
            'mitigated' => count(array_filter($scoped, fn ($r) => $r['status'] === 'mitigated')),
            'closed' => count(array_filter($scoped, fn ($r) => $r['status'] === 'closed')),
            'open_critical' => count(array_filter($scoped, fn ($r) => $open($r) && $r['level'] === 'critical')),
            'open_high' => count(array_filter($scoped, fn ($r) => $open($r) && $r['level'] === 'high')),
            'holes_with_open' => count(array_unique(array_column(array_filter($scoped, $open), 'hole_id'))),
        ];
        $rows = array_values(array_filter($scoped, fn ($r) => ($statuses === [] || in_array($r['status'], $statuses, true))
            && ($levels === [] || in_array($r['level'], $levels, true))));

        return ['items' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'total' => count($rows), 'counts' => $counts];
    }

    public function report(TenantContext $context, string $holeId, array $risk, string $idempotencyKey, string $correlationId): array
    {
        $this->calls[] = 'risks.create@1';
        foreach ($this->risks as $r) {
            if ($r['idempotency_key'] === $idempotencyKey) {
                return ['item' => $r, 'replayed' => true];
            }
        }
        $current = $this->currentDepth[$holeId] ?? null;
        $depth = $risk['depth_detected'] ?? null;
        if ($depth !== null && $current !== null && (float) $depth > (float) $current) {
            throw ValidationException::withMessages(['depth_detected' => "La profundidad detectada no puede superar la profundidad actual del pozo ({$current} m)."]);
        }
        $item = [
            'id' => 'rsk_'.count($this->risks),
            'hole_id' => $holeId,
            'hole_code' => 'H-'.$holeId,
            'level' => $risk['level'],
            'status' => 'open',
            'body' => $risk['body'],
            'depth_detected' => $depth !== null ? number_format((float) $depth, 2, '.', '') : null,
            'critical_distance' => $risk['critical_distance'] ?? null,
            'recommended_action' => $risk['recommended_action'] ?? null,
            'reported_by' => (string) $context->userId,
            'reported_at' => '2026-11-22T10:00:00Z',
            'idempotency_key' => $idempotencyKey,
        ];
        $this->risks[] = $item;

        return ['item' => $item, 'replayed' => false];
    }

    public function transition(TenantContext $context, string $riskId, string $to, ?string $notes, string $correlationId): ?array
    {
        $this->calls[] = 'risks.transition@1';
        foreach ($this->risks as $i => $r) {
            if ($r['id'] !== $riskId) {
                continue;
            }
            if ($r['status'] === 'closed') {
                throw ValidationException::withMessages(['status' => 'El riesgo ya está cerrado. Si reaparece, informá un riesgo nuevo.']);
            }
            if ($r['status'] === 'mitigated' && $to === 'mitigated') {
                throw ValidationException::withMessages(['status' => 'El riesgo ya está mitigado.']);
            }
            $prefix = $to === 'closed' ? 'closed' : 'mitigated';
            $this->risks[$i]['status'] = $to;
            $this->risks[$i][$prefix.'_by'] = (string) $context->userId;
            $this->risks[$i][$prefix.'_at'] = '2026-11-22T11:00:00Z';
            $this->risks[$i][$to === 'closed' ? 'closing_notes' : 'mitigation_notes'] = $notes;

            return $this->risks[$i];
        }

        return null;
    }
}
