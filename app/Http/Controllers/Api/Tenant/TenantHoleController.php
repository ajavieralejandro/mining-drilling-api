<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Repositories\OrganizationStore;
use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Tenant\HoleAccess;
use App\Support\GatewayException;
use App\Support\RequestCorrelation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pozos that live in the client's own database, reached exclusively
 * through the Data Gateway for the tenant resolved server-side from the
 * authenticated user's membership. Laravel's own DB is never consulted
 * here and never gains a copy of this data; the mine and project of each
 * hole come from the same client database. See
 * docs/sprints/distributed-data-vertical-slice.md.
 */
class TenantHoleController extends Controller
{
    public function __construct(
        private readonly TenantHoleRepositoryInterface $holes,
        private readonly OrganizationStore $organization,
        private readonly HoleAccess $access,
    ) {}

    public function index(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        $limit = (int) ($request->query('limit', 10));
        $limit = $limit > 0 ? min($limit, 50) : 10;
        $user = $request->user();

        if (! $this->access->seesAssigned($user, $context)) {
            return $this->forbidden();
        }

        try {
            $result = $this->visibleHoles($user, $context, $correlation->id, $limit);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        try {
            $items = $this->enrichWithStructure($result['items'], $context, $correlation->id);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

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

    public function show(Request $request, string $hole, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        try {
            if (! $this->access->canSee($request->user(), $context, $hole, $correlation->id)) {
                return $this->forbidden();
            }
            $result = $this->holes->find($context, $correlation->id, $hole);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        try {
            $enriched = $this->enrichWithStructure([$result['item']], $context, $correlation->id);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'data' => $enriched[0] ?? $result['item'],
            'origin_id' => $result['origin_id'] ?? null,
            'request_id' => $result['request_id'],
            'correlation_id' => $result['correlation_id'],
        ]);
    }

    /**
     * Holes the person may see. Whoever sees every hole gets the usual list;
     * a crew member gets their assigned holes, read one by one. On a
     * connector without assignments.by_user@1 the usual list is filtered by
     * each hole's crew instead.
     *
     * @return array{items: list<array<string, mixed>>, origin_id?: ?string, request_id: ?string, correlation_id: string}
     */
    private function visibleHoles(User $user, TenantContext $context, string $correlationId, int $limit): array
    {
        try {
            $ids = $this->access->assignedHoleIds($user, $context, $correlationId);
        } catch (GatewayException $e) {
            if ($e->errorCode !== 'UNSUPPORTED_OP') {
                throw $e;
            }
            $result = $this->holes->list($context, $correlationId, $limit);
            $result['items'] = $this->access->filterVisible($user, $context, $result['items'], $correlationId);

            return $result;
        }

        if ($ids === null) {
            return $this->holes->list($context, $correlationId, $limit);
        }

        $items = [];
        $meta = ['origin_id' => null, 'request_id' => null, 'correlation_id' => $correlationId];
        foreach (array_slice($ids, 0, $limit) as $id) {
            try {
                $found = $this->holes->find($context, $correlationId, $id);
            } catch (GatewayException $e) {
                // An assignment can outlive its hole in the client database.
                if ($e->errorCode === 'NOT_FOUND') {
                    continue;
                }
                throw $e;
            }
            $items[] = $found['item'];
            $meta['origin_id'] ??= $found['origin_id'] ?? null;
            $meta['request_id'] ??= $found['request_id'] ?? null;
        }

        return ['items' => $items] + $meta;
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Este pozo no está asignado a tu cuadrilla.']], 403);
    }

    /**
     * Adds the mine and project of each hole. The links live in the client
     * database too (undsurf_mine_holes), so they are read through the
     * connector. An older connector without the operation leaves the holes
     * without hierarchy instead of failing the list.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function enrichWithStructure(array $items, TenantContext $context, string $correlationId): array
    {
        $holeIds = array_values(array_unique(array_filter(array_map(
            fn (array $item) => isset($item['id']) ? (string) $item['id'] : null,
            $items
        ))));

        try {
            $links = $this->organization->lookupHoles($context, $holeIds, $correlationId);
        } catch (GatewayException $e) {
            if ($e->errorCode !== 'UNSUPPORTED_OP') {
                throw $e;
            }
            $links = [];
        }

        return array_map(function (array $item) use ($links) {
            $link = isset($item['id']) ? ($links[(string) $item['id']] ?? null) : null;
            if ($link === null) {
                return $item;
            }
            $mine = $link['mine'];
            $project = $link['project'];
            $item['mine'] = [
                'id' => $mine['id'] ?? null,
                'code' => $mine['code'] ?? null,
                'name' => $mine['name'] ?? null,
                'location' => $mine['location'] ?? null,
                'status' => $mine['status'] ?? null,
            ];
            $item['project'] = $project ? [
                'id' => $project['id'] ?? null,
                'code' => $project['code'] ?? null,
                'name' => $project['name'] ?? null,
                'status' => $project['status'] ?? null,
            ] : null;

            return $item;
        }, $items);
    }
}
