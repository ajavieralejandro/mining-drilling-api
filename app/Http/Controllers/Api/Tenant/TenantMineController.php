<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Repositories\OrganizationStore;
use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\OrganizationPresenter;
use App\Models\TenantMine;
use App\Policies\TenantStructurePolicy;
use App\Support\RequestCorrelation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mines of the tenant, in the client database through its connector. The
 * connector checks that the project exists and that the code is free.
 */
class TenantMineController extends Controller
{
    public function __construct(
        private readonly TenantStructurePolicy $policy,
        private readonly OrganizationStore $organization,
    ) {}

    public function index(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->view($request->user(), $context)) {
            return $this->forbidden('Acceso denegado.');
        }

        $includes = collect(explode(',', (string) $request->query('include', '')))
            ->map(fn (string $v) => trim($v))
            ->filter()
            ->all();

        $status = $request->filled('status') ? (string) $request->query('status') : null;
        if ($status !== null && ! in_array($status, TenantMine::VALID_STATUSES, true)) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $mines = $this->organization->listMines(
            $context,
            $request->filled('project_id') ? (string) $request->query('project_id') : null,
            $status,
            in_array('holes', $includes, true),
            $correlation->id,
        );

        return response()->json([
            'data' => OrganizationPresenter::mines($mines, $context->tenantId),
            'total' => count($mines),
        ]);
    }

    public function store(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manageMines($request->user(), $context)) {
            return $this->forbidden('No autorizado para crear minas.');
        }

        $this->normalizeCodeInput($request);
        $validated = $request->validate([
            'project_id' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'code' => ['nullable', 'string', 'max:64'],
            'location' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', 'string', Rule::in(TenantMine::VALID_STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $mine = $this->organization->saveMine($context, null, [
            'project_id' => $validated['project_id'],
            'name' => trim($validated['name']),
            'code' => $validated['code'] ?? null,
            'location' => isset($validated['location']) ? trim($validated['location']) : null,
            'status' => $validated['status'] ?? TenantMine::STATUS_ACTIVE,
            'description' => $validated['description'] ?? null,
        ], $correlation->id);

        return response()->json([
            'data' => OrganizationPresenter::mine($mine, $context->tenantId),
            'message' => 'Mina creada correctamente.',
        ], 201);
    }

    public function show(Request $request, string $mine, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->view($request->user(), $context)) {
            return $this->forbidden('Acceso denegado.');
        }

        $row = $this->organization->getMine($context, $mine, $correlation->id);
        if ($row === null) {
            return $this->notFound();
        }

        return response()->json(['data' => OrganizationPresenter::mine($row, $context->tenantId)]);
    }

    public function update(Request $request, string $mine, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manageMines($request->user(), $context)) {
            return $this->forbidden('No autorizado para editar minas.');
        }

        $this->normalizeCodeInput($request);
        $validated = $request->validate([
            'project_id' => ['sometimes', 'required', 'string', 'max:64'],
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'location' => ['sometimes', 'nullable', 'string', 'max:160'],
            'status' => ['sometimes', 'string', Rule::in(TenantMine::VALID_STATUSES)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        foreach (['name', 'location'] as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = trim($validated[$field]);
            }
        }

        $row = $validated === []
            ? $this->organization->getMine($context, $mine, $correlation->id)
            : $this->organization->saveMine($context, $mine, $validated, $correlation->id);
        if ($row === null) {
            return $this->notFound();
        }

        return response()->json([
            'data' => OrganizationPresenter::mine($row, $context->tenantId),
            'message' => 'Mina actualizada correctamente.',
        ]);
    }

    public function destroy(Request $request, string $mine, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manageMines($request->user(), $context)) {
            return $this->forbidden('No autorizado para eliminar minas.');
        }

        if (! $this->organization->deleteMine($context, $mine, $correlation->id)) {
            return $this->notFound();
        }

        return response()->json(['message' => 'Mina eliminada correctamente.']);
    }

    private function normalizeCodeInput(Request $request): void
    {
        if (! $request->has('code')) {
            return;
        }
        $code = $request->input('code');
        $trimmed = is_string($code) ? trim($code) : '';
        $request->merge(['code' => $trimmed === '' ? null : strtoupper($trimmed)]);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => $message]], 403);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Mina no encontrada.']], 404);
    }
}
