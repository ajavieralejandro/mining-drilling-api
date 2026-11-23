<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Repositories\PlanStore;
use App\Http\Controllers\Controller;
use App\Policies\TenantPlanPolicy;
use App\Support\RequestCorrelation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Drilling plans, their platforms and the holes drilled from each
 * platform, kept in the client database through the connector. The
 * connector checks codes, the plan and mine references and that each hole
 * exists in the client's hole table.
 */
class TenantPlanController extends Controller
{
    private const PLAN_STATUSES = ['planned', 'in_progress', 'paused', 'completed'];

    private const PURPOSES = ['exploration', 'infill', 'production', 'ventilation'];

    private const PLATFORM_STATUSES = ['planned', 'active', 'paused', 'completed'];

    private const DECIMAL = 'regex:/^-?\d+(\.\d{1,2})?$/';

    public function __construct(
        private readonly PlanStore $plans,
        private readonly TenantPlanPolicy $policy,
    ) {}

    public function index(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->view($request->user(), $context)) {
            return $this->forbidden('No autorizado para ver planes de perforación.');
        }
        $status = $request->filled('status') ? (string) $request->query('status') : null;
        if ($status !== null && ! in_array($status, self::PLAN_STATUSES, true)) {
            return response()->json(['data' => [], 'can_manage' => $this->policy->manage($request->user(), $context)]);
        }
        $result = $this->plans->plans(
            $context,
            $request->filled('mine_id') ? (string) $request->query('mine_id') : null,
            $status,
            $correlation->id,
        );

        return response()->json([
            'data' => array_map([$this, 'plan'], $result['items']),
            'can_manage' => $this->policy->manage($request->user(), $context),
        ]);
    }

    public function show(Request $request, string $plan, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->view($request->user(), $context)) {
            return $this->forbidden('No autorizado para ver planes de perforación.');
        }
        $row = $this->plans->plan($context, $plan, $correlation->id);

        return $row === null ? $this->notFound('Plan no encontrado.') : response()->json([
            'data' => $this->plan($row),
            'can_manage' => $this->policy->manage($request->user(), $context),
        ]);
    }

    public function store(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        return $this->savePlan($request, null, $context, $correlation);
    }

    public function update(Request $request, string $plan, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        return $this->savePlan($request, $plan, $context, $correlation);
    }

    public function destroy(Request $request, string $plan, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manage($request->user(), $context)) {
            return $this->forbidden('No autorizado para administrar planes de perforación.');
        }

        return $this->plans->deletePlan($context, $plan, $correlation->id)
            ? response()->json(['message' => 'Plan eliminado, con sus plataformas. Los pozos no se borran.'])
            : $this->notFound('Plan no encontrado.');
    }

    public function showPlatform(Request $request, string $platform, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->view($request->user(), $context)) {
            return $this->forbidden('No autorizado para ver plataformas.');
        }
        $row = $this->plans->platform($context, $platform, $correlation->id);

        return $row === null ? $this->notFound('Plataforma no encontrada.') : response()->json([
            'data' => $row,
            'can_manage' => $this->policy->manage($request->user(), $context),
        ]);
    }

    public function storePlatform(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        return $this->savePlatform($request, null, $context, $correlation);
    }

    public function updatePlatform(Request $request, string $platform, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        return $this->savePlatform($request, $platform, $context, $correlation);
    }

    public function destroyPlatform(Request $request, string $platform, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manage($request->user(), $context)) {
            return $this->forbidden('No autorizado para administrar plataformas.');
        }

        return $this->plans->deletePlatform($context, $platform, $correlation->id)
            ? response()->json(['message' => 'Plataforma eliminada. Los pozos no se borran.'])
            : $this->notFound('Plataforma no encontrada.');
    }

    public function attachHoles(Request $request, string $platform, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manage($request->user(), $context)) {
            return $this->forbidden('No autorizado para administrar plataformas.');
        }
        $validated = $request->validate([
            'holes' => ['required', 'array', 'min:1', 'max:200'],
            'holes.*.hole_id' => ['required', 'string', 'max:64', 'distinct'],
            'holes.*.sequence' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'holes.*.target' => ['nullable', 'string', 'max:120'],
        ]);
        $holes = array_map(fn (array $h) => [
            'hole_id' => trim($h['hole_id']),
            'sequence' => isset($h['sequence']) ? (int) $h['sequence'] : null,
            'target' => isset($h['target']) ? trim($h['target']) : null,
        ], $validated['holes']);

        $row = $this->plans->attachHoles($context, $platform, $holes, $correlation->id);

        return $row === null ? $this->notFound('Plataforma no encontrada.') : response()->json([
            'data' => $row,
            'message' => 'Pozos ubicados en la plataforma.',
        ]);
    }

    public function detachHole(Request $request, string $platform, string $hole, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manage($request->user(), $context)) {
            return $this->forbidden('No autorizado para administrar plataformas.');
        }

        return $this->plans->detachHole($context, $platform, $hole, $correlation->id)
            ? response()->json(['message' => 'Pozo quitado de la plataforma.'])
            : $this->notFound('El pozo no está en esta plataforma.');
    }

    private function savePlan(Request $request, ?string $id, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manage($request->user(), $context)) {
            return $this->forbidden('No autorizado para administrar planes de perforación.');
        }
        $creating = $id === null;
        $validated = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:160'],
            'purpose' => [$creating ? 'required' : 'sometimes', Rule::in(self::PURPOSES)],
            'code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'mine_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'level' => ['sometimes', 'nullable', 'string', 'max:64'],
            'sector' => ['sometimes', 'nullable', 'string', 'max:120'],
            'planned_meters' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999', self::DECIMAL],
            'status' => ['sometimes', Rule::in(self::PLAN_STATUSES)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $row = $this->plans->savePlan($context, $id, $this->fields($validated), $correlation->id);
        if ($row === null) {
            return $this->notFound('Plan no encontrado.');
        }

        return response()->json([
            'data' => $this->plan($row),
            'message' => $creating ? 'Plan creado.' : 'Plan actualizado.',
        ], $creating ? 201 : 200);
    }

    private function savePlatform(Request $request, ?string $id, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manage($request->user(), $context)) {
            return $this->forbidden('No autorizado para administrar plataformas.');
        }
        $creating = $id === null;
        $validated = $request->validate([
            'plan_id' => [$creating ? 'required' : 'sometimes', 'string', 'max:64'],
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'easting' => ['sometimes', 'nullable', 'numeric', self::DECIMAL],
            'northing' => ['sometimes', 'nullable', 'numeric', self::DECIMAL],
            'elevation' => ['sometimes', 'nullable', 'numeric', self::DECIMAL],
            'gallery' => ['sometimes', 'nullable', 'string', 'max:120'],
            'level' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', Rule::in(self::PLATFORM_STATUSES)],
        ]);

        $row = $this->plans->savePlatform($context, $id, $this->fields($validated), $correlation->id);
        if ($row === null) {
            return $this->notFound('Plataforma no encontrada.');
        }

        return response()->json([
            'data' => $row,
            'message' => $creating ? 'Plataforma creada.' : 'Plataforma actualizada.',
        ], $creating ? 201 : 200);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, string|null>
     */
    private function fields(array $validated): array
    {
        return array_map(fn ($v) => $v === null ? null : trim((string) $v), $validated);
    }

    /**
     * Plans come back as the connector reads them; the author id is cast to
     * the UndSurf user id when it is one.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function plan(array $row): array
    {
        $by = $row['created_by'] ?? null;
        $row['created_by'] = ctype_digit((string) ($by ?? '')) ? (int) $by : $by;

        return $row;
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => $message]], 403);
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => $message]], 404);
    }
}
