<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Repositories\HoleRecordsStore;
use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\ProgressReportPresenter;
use App\Policies\TenantHoleProgressPolicy;
use App\Support\GatewayException;
use App\Support\RequestCorrelation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Mine": the signed-in person's own crew assignments and daily reports,
 * read from the client database (plan 16, E1 and E2). They feed the web's
 * "Mi turno" for drillers and helpers.
 */
class TenantMeController extends Controller
{
    public function __construct(
        private readonly HoleRecordsStore $records,
        private readonly TenantHoleRepositoryInterface $holes,
        private readonly TenantHoleProgressPolicy $progressPolicy,
    ) {}

    /** GET /tenant/me/assignments — every active member may read their own. */
    public function assignments(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        $user = $request->user();
        $result = $this->records->assignmentsOf($context, $user->id, $correlation->id);

        $holes = [];
        $data = [];
        foreach ($result['active'] as $assignment) {
            $holeId = (string) ($assignment['hole_id'] ?? '');
            if ($holeId === '') {
                continue;
            }
            $holes[$holeId] ??= $this->hole($context, $holeId, $correlation->id);
            $data[] = [
                'hole_id' => $holeId,
                'hole_code' => $holes[$holeId]['code'] ?? null,
                'hole_status' => $holes[$holeId]['status'] ?? null,
                'role_in_hole' => $assignment['role_in_hole'] ?? null,
                'assigned_from' => $assignment['assigned_from'] ?? null,
                'assigned_to' => $assignment['assigned_to'] ?? null,
                'notes' => $assignment['notes'] ?? null,
            ];
        }

        return response()->json([
            'data' => [
                'shift' => $user->shift,
                'assignments' => $data,
            ],
            'origin_id' => $result['origin_id'] ?? null,
            'correlation_id' => $correlation->id,
        ]);
    }

    /**
     * GET /tenant/me/progress?limit= — the reports the person logged on the
     * holes of their active crew, newest first. Needs progress.view.
     */
    public function progress(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        $user = $request->user();
        if (! $this->progressPolicy->viewAny($user, $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado']], 403);
        }
        $limit = (int) $request->query('limit', 10);
        $limit = $limit > 0 ? min($limit, 50) : 10;

        $active = $this->records->assignmentsOf($context, $user->id, $correlation->id)['active'];
        $holeIds = array_values(array_unique(array_filter(array_map(fn (array $a) => (string) ($a['hole_id'] ?? ''), $active))));

        $items = [];
        $originId = null;
        foreach ($holeIds as $holeId) {
            $page = $this->records->queryProgress($context, $holeId, null, 1, 50, $correlation->id);
            $originId ??= $page['origin_id'] ?? null;
            foreach ($page['items'] as $item) {
                if ((string) ($item['user_id'] ?? '') === (string) $user->id) {
                    $items[] = $item;
                }
            }
        }
        usort($items, fn (array $a, array $b) => strcmp((string) ($b['logged_at'] ?? ''), (string) ($a['logged_at'] ?? '')));
        $items = array_slice($items, 0, $limit);

        return response()->json([
            'data' => ProgressReportPresenter::many($items, $context->tenantId, $originId),
            'correlation_id' => $correlation->id,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function hole(TenantContext $context, string $holeId, string $correlationId): ?array
    {
        try {
            return $this->holes->find($context, $correlationId, $holeId)['item'];
        } catch (GatewayException $e) {
            // An assignment can outlive its hole in the client database.
            if ($e->errorCode === 'NOT_FOUND') {
                return null;
            }
            throw $e;
        }
    }
}
