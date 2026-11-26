<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Repositories\OrganizationStore;
use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\OrganizationPresenter;
use App\Policies\TenantStructurePolicy;
use App\Services\Tenant\HoleAccess;
use App\Support\RequestCorrelation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hole ↔ mine links, in the client database through its connector. The
 * connector checks that each hole exists in the client's hole table and
 * takes its code from there; a hole has one mine, so linking it again moves
 * it.
 */
class TenantMineHoleController extends Controller
{
    public function __construct(
        private readonly TenantStructurePolicy $policy,
        private readonly OrganizationStore $organization,
    ) {}

    public function index(Request $request, string $mine, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->view($request->user(), $context)) {
            return $this->forbidden('Acceso denegado.');
        }

        $links = $this->organization->mineHoles($context, $mine, $correlation->id);
        if ($links === null) {
            return $this->mineNotFound();
        }
        $visible = app(HoleAccess::class)->visibleHoleIds($request->user(), $context, $correlation->id);
        if ($visible !== null) {
            $links = HoleAccess::onlyLinks($links, $visible);
        }

        return response()->json([
            'data' => OrganizationPresenter::holeLinks($links, $context->tenantId),
            'total' => count($links),
        ]);
    }

    public function attach(Request $request, string $mine, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manageMineHoles($request->user(), $context)) {
            return $this->forbidden('No autorizado para vincular pozos a una mina.');
        }

        $validated = $request->validate([
            'hole_id' => ['required_without:holes', 'nullable', 'string', 'max:64'],
            'origin_id' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'holes' => ['sometimes', 'array', 'min:1', 'max:200'],
            'holes.*.hole_id' => ['required', 'string', 'max:64', 'distinct'],
            'holes.*.origin_id' => ['nullable', 'string', 'max:64'],
            'holes.*.status' => ['nullable', 'string', 'max:32'],
            'holes.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $batch = isset($validated['holes']);
        $entries = $batch ? $validated['holes'] : [$validated];
        $holes = array_map(fn (array $e) => [
            'hole_id' => trim((string) $e['hole_id']),
            'origin_id' => $e['origin_id'] ?? null,
            'status' => $e['status'] ?? null,
            'notes' => $e['notes'] ?? null,
        ], $entries);

        $saved = $this->organization->attachHoles($context, $mine, $holes, $correlation->id);
        if ($saved === null) {
            return $this->mineNotFound();
        }
        $data = OrganizationPresenter::holeLinks($saved, $context->tenantId);

        if ($batch) {
            return response()->json([
                'message' => 'Pozos vinculados a la mina correctamente.',
                'data' => $data,
                'total' => count($data),
            ]);
        }

        return response()->json([
            'data' => $data[0] ?? null,
            'message' => 'Pozo vinculado a la mina correctamente.',
        ], ($saved[0]['created'] ?? false) ? 201 : 200);
    }

    public function detach(Request $request, string $mine, string $hole, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manageMineHoles($request->user(), $context)) {
            return $this->forbidden('No autorizado para desvincular pozos de una mina.');
        }

        if (! $this->organization->detachHole($context, $mine, $hole, $correlation->id)) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'El pozo no está vinculado a esta mina.']], 404);
        }

        return response()->json(['message' => 'Pozo desvinculado de la mina correctamente.']);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => $message]], 403);
    }

    private function mineNotFound(): JsonResponse
    {
        return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Mina no encontrada.']], 404);
    }
}
