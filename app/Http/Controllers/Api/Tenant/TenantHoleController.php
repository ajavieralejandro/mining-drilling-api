<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Models\TenantMineHole;
use App\Support\GatewayException;
use App\Support\RequestCorrelation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Pozos that live in the client's own database, reached exclusively
 * through the Data Gateway for the tenant resolved server-side from the
 * authenticated user's membership. Laravel's own DB is never consulted
 * here and never gains a copy of this data. See
 * docs/sprints/distributed-data-vertical-slice.md.
 */
class TenantHoleController extends Controller
{
    public function __construct(private readonly TenantHoleRepositoryInterface $holes) {}

    public function index(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        $limit = (int) ($request->query('limit', 10));
        $limit = $limit > 0 ? min($limit, 50) : 10;

        try {
            $result = $this->holes->list($context, $correlation->id, $limit);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        $items = $this->enrichWithStructure($result['items'], $context->tenantId);

        if ($request->filled('mine_id')) {
            $mineId = (string) $request->query('mine_id');
            $items = array_values(array_filter(
                $items,
                fn (array $item) => ($item['mine']['id'] ?? null) === $mineId
            ));
        }

        if ($request->filled('project_id')) {
            $projectId = (string) $request->query('project_id');
            $items = array_values(array_filter(
                $items,
                fn (array $item) => ($item['project']['id'] ?? null) === $projectId
            ));
        }

        return response()->json([
            'data' => $items,
            'origin_id' => $result['origin_id'] ?? null,
            'request_id' => $result['request_id'],
            'correlation_id' => $result['correlation_id'],
        ]);
    }

    public function show(string $hole, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        try {
            $result = $this->holes->find($context, $correlation->id, $hole);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        $enriched = $this->enrichWithStructure([$result['item']], $context->tenantId);

        return response()->json([
            'data' => $enriched[0] ?? $result['item'],
            'origin_id' => $result['origin_id'] ?? null,
            'request_id' => $result['request_id'],
            'correlation_id' => $result['correlation_id'],
        ]);
    }

    /**
     * Enrich gateway hole items with control-plane mine/project metadata if linked.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function enrichWithStructure(array $items, string $tenantId): array
    {
        if (empty($items) || ! Schema::hasTable('tenant_mine_holes')) {
            return $items;
        }

        $holeIds = array_values(array_filter(array_map(
            fn (array $item) => isset($item['id']) ? (string) $item['id'] : null,
            $items
        )));

        if (empty($holeIds)) {
            return $items;
        }

        $links = TenantMineHole::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('hole_id', $holeIds)
            ->with(['mine' => fn ($q) => $q->with('project:id,code,name,status')])
            ->get()
            ->keyBy('hole_id');

        if ($links->isEmpty()) {
            return $items;
        }

        return array_map(function (array $item) use ($links) {
            $id = isset($item['id']) ? (string) $item['id'] : null;
            $link = $id !== null ? $links->get($id) : null;
            $mine = $link?->mine;
            $project = $mine?->project;

            if ($mine !== null) {
                $item['mine'] = [
                    'id' => $mine->id,
                    'code' => $mine->code,
                    'name' => $mine->name,
                    'location' => $mine->location,
                    'status' => $mine->status,
                ];
                $item['project'] = $project ? [
                    'id' => $project->id,
                    'code' => $project->code,
                    'name' => $project->name,
                    'status' => $project->status,
                ] : null;
            }

            return $item;
        }, $items);
    }
}
