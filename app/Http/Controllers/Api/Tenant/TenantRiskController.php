<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Repositories\RiskStore;
use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\RiskPresenter;
use App\Policies\TenantRiskPolicy;
use App\Services\Tenant\HoleAccess;
use App\Services\Tenant\HoleCrew;
use App\Support\RequestCorrelation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Risks of the company's holes, kept in the client database through the
 * connector. The connector checks the depth against what was drilled and
 * that each step of the lifecycle (open → mitigated → closed) is allowed.
 */
class TenantRiskController extends Controller
{
    private const LEVELS = ['low', 'medium', 'high', 'critical'];

    private const STATUSES = ['open', 'mitigated', 'closed'];

    public function __construct(
        private readonly RiskStore $risks,
        private readonly HoleCrew $crew,
        private readonly TenantRiskPolicy $policy,
    ) {}

    /** Global list for the company, with counts for the summary. */
    public function index(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        return $this->list($request, $context, $correlation, null);
    }

    public function holeIndex(Request $request, string $hole, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        return $this->list($request, $context, $correlation, $hole);
    }

    public function store(Request $request, string $hole, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->report($request->user(), $context, fn () => $this->crew->userIds($context, $hole, $correlation->id))) {
            return $this->forbidden('Solo la cuadrilla asignada al pozo, el supervisor o el administrador pueden informar riesgos.');
        }
        $validated = $request->validate([
            'level' => ['required', Rule::in(self::LEVELS)],
            'body' => ['required', 'string', 'max:2000'],
            'depth_detected' => ['nullable', 'numeric', 'min:0', 'max:99999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'critical_distance' => ['nullable', 'numeric', 'min:0', 'max:99999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'recommended_action' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
        ]);

        $result = $this->risks->report($context, $hole, [
            'level' => $validated['level'],
            'body' => trim($validated['body']),
            'depth_detected' => isset($validated['depth_detected']) ? (string) $validated['depth_detected'] : null,
            'critical_distance' => isset($validated['critical_distance']) ? (string) $validated['critical_distance'] : null,
            'recommended_action' => isset($validated['recommended_action']) ? trim($validated['recommended_action']) : null,
        ], $validated['idempotency_key'], $correlation->id);

        return response()->json([
            'data' => RiskPresenter::one($result['item']),
            'message' => $result['replayed'] ? 'El riesgo ya estaba informado.' : 'Riesgo informado.',
        ], $result['replayed'] ? 200 : 201);
    }

    public function mitigate(Request $request, string $risk, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        return $this->transition($request, $risk, 'mitigated', $context, $correlation);
    }

    public function close(Request $request, string $risk, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        return $this->transition($request, $risk, 'closed', $context, $correlation);
    }

    private function transition(Request $request, string $risk, string $to, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->resolve($request->user(), $context)) {
            return $this->forbidden('No autorizado para mitigar o cerrar riesgos.');
        }
        $validated = $request->validate([
            'notes' => [$to === 'closed' ? 'required' : 'nullable', 'string', 'max:2000'],
        ]);

        $item = $this->risks->transition($context, $risk, $to, isset($validated['notes']) ? trim($validated['notes']) : null, $correlation->id);
        if ($item === null) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Riesgo no encontrado.']], 404);
        }

        return response()->json([
            'data' => RiskPresenter::one($item),
            'message' => $to === 'closed' ? 'Riesgo cerrado.' : 'Riesgo marcado como mitigado.',
        ]);
    }

    private function list(Request $request, TenantContext $context, RequestCorrelation $correlation, ?string $hole): JsonResponse
    {
        if (! $this->policy->view($request->user(), $context)) {
            return $this->forbidden('No autorizado para ver riesgos.');
        }
        if ($hole !== null && ($denied = app(HoleAccess::class)->denyUnlessVisible($request->user(), $context, $hole, $correlation->id))) {
            return $denied;
        }
        $statuses = array_values(array_intersect($this->listParam($request, 'status'), self::STATUSES));
        $levels = array_values(array_intersect($this->listParam($request, 'level'), self::LEVELS));
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $page = max((int) $request->query('page', 1), 1);

        $visible = $hole === null ? app(HoleAccess::class)->visibleHoleIds($request->user(), $context, $correlation->id) : null;
        $result = $visible === null
            ? $this->risks->risks($context, $hole, $statuses, $levels, $page, $perPage, $correlation->id)
            : $this->risksOfHoles($context, $visible, $statuses, $levels, $page, $perPage, $correlation->id);

        $response = [
            'data' => RiskPresenter::many($result['items']),
            'counts' => $result['counts'],
            'pagination' => [
                'total' => $result['total'],
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($result['total'] / $perPage)),
            ],
            'can_resolve' => $this->policy->resolve($request->user(), $context),
        ];
        if ($hole !== null) {
            $response['can_report'] = $this->policy->report($request->user(), $context, fn () => $this->crew->userIds($context, $hole, $correlation->id));
        }

        return response()->json($response);
    }

    /**
     * Accepts `status=open,mitigated` and `status[]=open`.
     *
     * @return list<string>
     */
    /**
     * The company-wide list limited to a few holes (plan 15, C3): one query
     * per hole, merged newest first and paginated here. Fine for a crew
     * member's handful of holes.
     *
     * @param  list<string>  $holeIds
     * @param  list<string>  $statuses
     * @param  list<string>  $levels
     * @return array{items: list<array<string, mixed>>, total: int, counts: array<string, int>}
     */
    private function risksOfHoles(TenantContext $context, array $holeIds, array $statuses, array $levels, int $page, int $perPage, string $correlationId): array
    {
        $items = [];
        $counts = ['open' => 0, 'mitigated' => 0, 'closed' => 0, 'open_critical' => 0, 'open_high' => 0, 'holes_with_open' => 0];
        foreach ($holeIds as $holeId) {
            $part = $this->risks->risks($context, $holeId, $statuses, $levels, 1, 100, $correlationId);
            array_push($items, ...$part['items']);
            foreach ($part['counts'] as $key => $value) {
                $counts[$key] = ($counts[$key] ?? 0) + (int) $value;
            }
        }
        usort($items, fn (array $a, array $b) => strcmp((string) ($b['reported_at'] ?? ''), (string) ($a['reported_at'] ?? '')));

        return [
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'total' => count($items),
            'counts' => $counts,
        ];
    }

    private function listParam(Request $request, string $key): array
    {
        $raw = $request->query($key);
        $values = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $values)));
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => $message]], 403);
    }
}
