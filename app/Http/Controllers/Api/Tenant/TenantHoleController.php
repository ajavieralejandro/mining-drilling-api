<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Support\GatewayException;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $limit = (int) ($request->query('limit', 10));
        $limit = $limit > 0 ? min($limit, 50) : 10;

        try {
            $items = $this->holes->list($context, $limit);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        return response()->json(['data' => $items]);
    }

    public function show(string $hole, TenantContext $context): JsonResponse
    {
        try {
            $item = $this->holes->find($context, $hole);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        return response()->json(['data' => $item]);
    }
}
