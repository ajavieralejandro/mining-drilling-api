<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Repositories\HoleRecordsStore;
use App\Domain\Repositories\ObservationStore;
use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\ObservationPresenter;
use App\Http\Resources\Tenant\RiskPresenter;
use App\Policies\TenantObservationPolicy;
use App\Policies\TenantRiskPolicy;
use App\Support\RequestCorrelation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Observation log of a real hole, the company's observation types and the
 * hole geometry for the graphic views. Everything is read from and written
 * to the client database through the connector; the connector checks that
 * the type is active and that the depth is not below what was drilled.
 */
class TenantObservationController extends Controller
{
    public function __construct(
        private readonly ObservationStore $observations,
        private readonly HoleRecordsStore $records,
        private readonly TenantObservationPolicy $policy,
        private readonly TenantRiskPolicy $riskPolicy,
    ) {}

    public function types(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->view($request->user(), $context)) {
            return $this->forbidden('No autorizado para ver observaciones.');
        }
        $includeInactive = $request->boolean('include_inactive')
            && $this->policy->manageTypes($request->user(), $context);

        return response()->json([
            'data' => $this->observations->types($context, $includeInactive, $correlation->id),
            'can_manage' => $this->policy->manageTypes($request->user(), $context),
        ]);
    }

    public function storeType(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manageTypes($request->user(), $context)) {
            return $this->forbidden('No autorizado para administrar tipos de observación.');
        }
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:80'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);

        $type = $this->observations->saveType($context, null, $this->typeFields($validated), $correlation->id);

        return response()->json(['data' => $type, 'message' => 'Tipo de observación creado.'], 201);
    }

    public function updateType(Request $request, string $type, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manageTypes($request->user(), $context)) {
            return $this->forbidden('No autorizado para administrar tipos de observación.');
        }
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);

        $saved = $this->observations->saveType($context, $type, $this->typeFields($validated), $correlation->id);
        if ($saved === null) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Tipo de observación no encontrado.']], 404);
        }

        return response()->json(['data' => $saved, 'message' => 'Tipo de observación actualizado.']);
    }

    public function index(Request $request, string $hole, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->view($request->user(), $context)) {
            return $this->forbidden('No autorizado para ver observaciones.');
        }
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $page = max((int) $request->query('page', 1), 1);
        $typeId = $request->filled('type_id') ? (string) $request->query('type_id') : null;

        $result = $this->observations->observations($context, $hole, $typeId, $page, $perPage, $correlation->id);

        return response()->json([
            'data' => ObservationPresenter::many($result['items']),
            'pagination' => [
                'total' => $result['total'],
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($result['total'] / $perPage)),
            ],
            'can_create' => $this->policy->create($request->user(), $context, fn () => $this->crewUserIds($context, $hole, $correlation->id)),
        ]);
    }

    public function store(Request $request, string $hole, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->create($request->user(), $context, fn () => $this->crewUserIds($context, $hole, $correlation->id))) {
            return $this->forbidden('Solo la cuadrilla asignada al pozo, el supervisor o el administrador pueden registrar observaciones.');
        }
        $validated = $request->validate([
            'type_id' => ['required', 'string', 'max:64'],
            'body' => ['required', 'string', 'max:2000'],
            'depth' => ['nullable', 'numeric', 'min:0', 'max:99999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
        ]);

        $result = $this->observations->record(
            $context,
            $hole,
            $validated['type_id'],
            trim($validated['body']),
            isset($validated['depth']) ? (string) $validated['depth'] : null,
            $validated['idempotency_key'],
            $correlation->id,
        );

        return response()->json([
            'data' => ObservationPresenter::one($result['item']),
            'message' => $result['replayed'] ? 'La observación ya estaba registrada.' : 'Observación registrada.',
        ], $result['replayed'] ? 200 : 201);
    }

    public function geometry(Request $request, string $hole, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        $geometry = $this->observations->geometry($context, $hole, $correlation->id);
        if (! $this->policy->view($request->user(), $context)) {
            $geometry['marks'] = [];
        }
        if (! $this->riskPolicy->view($request->user(), $context)) {
            $geometry['risks'] = [];
        }
        $geometry['marks'] = ObservationPresenter::many($geometry['marks']);
        $geometry['risks'] = RiskPresenter::many($geometry['risks'] ?? []);

        return response()->json(['data' => $geometry]);
    }

    /**
     * @return list<int>
     */
    private function crewUserIds(TenantContext $context, string $holeId, string $correlationId): array
    {
        $crew = $this->records->crew($context, $holeId, $correlationId);

        return array_values(array_filter(array_map(
            fn (array $a) => ctype_digit((string) ($a['user_id'] ?? '')) ? (int) $a['user_id'] : null,
            $crew['active'] ?? [],
        )));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, string|null>
     */
    private function typeFields(array $validated): array
    {
        $fields = [];
        foreach (['code', 'name'] as $k) {
            if (array_key_exists($k, $validated)) {
                $fields[$k] = trim((string) $validated[$k]);
            }
        }
        if (array_key_exists('active', $validated)) {
            $fields['active'] = filter_var($validated['active'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        }
        if (array_key_exists('sort_order', $validated)) {
            $fields['sort_order'] = (string) (int) $validated['sort_order'];
        }

        return $fields;
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => $message]], 403);
    }
}
