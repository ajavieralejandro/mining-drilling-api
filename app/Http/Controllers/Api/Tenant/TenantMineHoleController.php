<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\TenantMineHoleResource;
use App\Models\DrillHole;
use App\Models\TenantMine;
use App\Models\TenantMineHole;
use App\Policies\TenantStructurePolicy;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantMineHoleController extends Controller
{
    public function __construct(
        private readonly TenantStructurePolicy $policy,
    ) {}

    public function index(Request $request, string $mine, TenantContext $context): JsonResponse
    {
        if (! $this->policy->view($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado.']], 403);
        }

        $mineModel = TenantMine::query()
            ->where('tenant_id', $context->tenantId)
            ->find($mine);

        if (! $mineModel) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Mina no encontrada.']], 404);
        }

        $links = TenantMineHole::query()
            ->where('tenant_id', $context->tenantId)
            ->where('mine_id', $mineModel->id)
            ->with('mine:id,code,name,location,project_id')
            ->orderBy('hole_code')
            ->orderBy('hole_id')
            ->get();

        return response()->json([
            'data' => TenantMineHoleResource::collection($links),
            'total' => $links->count(),
        ]);
    }

    public function attach(Request $request, string $mine, TenantContext $context): JsonResponse
    {
        if (! $this->policy->manageMineHoles($request->user(), $context)) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'No autorizado para vincular pozos a una mina.'],
            ], 403);
        }

        $mineModel = TenantMine::query()
            ->where('tenant_id', $context->tenantId)
            ->find($mine);

        if (! $mineModel) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Mina no encontrada.']], 404);
        }

        $validated = $request->validate([
            'hole_id' => ['required_without_all:holes,drill_hole_id', 'nullable', 'string', 'max:64'],
            'drill_hole_id' => ['nullable', 'integer', 'exists:drill_holes,id'],
            'hole_code' => ['nullable', 'string', 'max:64'],
            'origin_id' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'holes' => ['sometimes', 'array', 'min:1'],
            'holes.*.hole_id' => ['required_without:holes.*.drill_hole_id', 'nullable', 'string', 'max:64'],
            'holes.*.drill_hole_id' => ['nullable', 'integer', 'exists:drill_holes,id'],
            'holes.*.hole_code' => ['nullable', 'string', 'max:64'],
            'holes.*.origin_id' => ['nullable', 'string', 'max:64'],
            'holes.*.status' => ['nullable', 'string', 'max:32'],
            'holes.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (isset($validated['holes'])) {
            $saved = DB::transaction(function () use ($validated, $mineModel, $context, $request) {
                $items = [];
                foreach ($validated['holes'] as $entry) {
                    $items[] = $this->upsertHoleLink($entry, $mineModel, $context, $request->user()->id);
                }

                return $items;
            });

            return response()->json([
                'message' => 'Pozos vinculados a la mina correctamente.',
                'data' => TenantMineHoleResource::collection(collect($saved)),
                'total' => count($saved),
            ], 200);
        }

        $link = $this->upsertHoleLink($validated, $mineModel, $context, $request->user()->id);
        $status = $link->wasRecentlyCreated ? 201 : 200;

        return (new TenantMineHoleResource($link))
            ->additional(['message' => 'Pozo vinculado a la mina correctamente.'])
            ->response()
            ->setStatusCode($status);
    }

    public function detach(Request $request, string $mine, string $hole, TenantContext $context): JsonResponse
    {
        if (! $this->policy->manageMineHoles($request->user(), $context)) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'No autorizado para desvincular pozos de una mina.'],
            ], 403);
        }

        $mineModel = TenantMine::query()
            ->where('tenant_id', $context->tenantId)
            ->find($mine);

        if (! $mineModel) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Mina no encontrada.']], 404);
        }

        $link = TenantMineHole::query()
            ->where('tenant_id', $context->tenantId)
            ->where('mine_id', $mineModel->id)
            ->where(function ($q) use ($hole) {
                $q->where('hole_id', $hole)->orWhere('id', $hole);
            })
            ->first();

        if (! $link) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'El pozo no está vinculado a esta mina.']], 404);
        }

        $link->delete();

        return response()->json(['message' => 'Pozo desvinculado de la mina correctamente.']);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function upsertHoleLink(array $entry, TenantMine $mine, TenantContext $context, int $userId): TenantMineHole
    {
        $drillHole = null;
        if (! empty($entry['drill_hole_id'])) {
            $drillHole = DrillHole::query()->find((int) $entry['drill_hole_id']);
        }

        $holeId = trim((string) ($entry['hole_id'] ?? $drillHole?->id ?? ''));
        $holeCode = $entry['hole_code'] ?? $drillHole?->hole_id ?? $holeId;

        $link = TenantMineHole::updateOrCreate(
            [
                'tenant_id' => $context->tenantId,
                'hole_id' => $holeId,
            ],
            [
                'mine_id' => $mine->id,
                'origin_id' => $entry['origin_id'] ?? null,
                'hole_code' => trim((string) $holeCode),
                'drill_hole_id' => $drillHole?->id,
                'status' => $entry['status'] ?? ($drillHole?->status?->value ?? null),
                'notes' => $entry['notes'] ?? null,
                'assigned_by' => $userId,
            ]
        );

        $link->load('mine:id,code,name,location,project_id');

        return $link;
    }
}
