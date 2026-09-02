<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Support\GatewayException;
use App\Support\RequestCorrelation;
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

    public function index(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        $limit = (int) ($request->query('limit', 10));
        $limit = $limit > 0 ? min($limit, 50) : 10;

        try {
            $result = $this->holes->list($context, $correlation->id, $limit);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'data' => $result['items'],
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

        return response()->json([
            'data' => $result['item'],
            'request_id' => $result['request_id'],
            'correlation_id' => $result['correlation_id'],
        ]);
    }
}
