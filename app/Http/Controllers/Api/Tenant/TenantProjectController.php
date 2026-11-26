<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Repositories\OrganizationStore;
use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\OrganizationPresenter;
use App\Models\Tenant;
use App\Models\TenantProject;
use App\Policies\TenantStructurePolicy;
use App\Services\Tenant\HoleAccess;
use App\Support\RequestCorrelation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Projects of the tenant. They live in the client database, reached through
 * its connector; code uniqueness is checked there, in the same transaction
 * that writes the row.
 */
class TenantProjectController extends Controller
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
        $withHoles = in_array('holes', $includes, true);
        $withMines = $withHoles || in_array('mines', $includes, true);

        $status = $request->filled('status') ? (string) $request->query('status') : null;
        if ($status !== null && ! in_array($status, TenantProject::VALID_STATUSES, true)) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $projects = $this->organization->listProjects($context, $status, $withMines, $withHoles, $correlation->id);

        return response()->json([
            'data' => OrganizationPresenter::projects($projects, $context->tenantId),
            'total' => count($projects),
        ]);
    }

    public function hierarchy(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->view($request->user(), $context)) {
            return $this->forbidden('Acceso denegado.');
        }

        $tenant = Tenant::query()->findOrFail($context->tenantId);
        $projects = $this->organization->listProjects($context, null, true, true, $correlation->id);
        // Plan 15, C3: whoever sees only assigned holes gets only the branch that holds them.
        $visible = app(HoleAccess::class)->visibleHoleIds($request->user(), $context, $correlation->id);
        if ($visible !== null) {
            $projects = HoleAccess::onlyHierarchy($projects, $visible);
        }

        return response()->json([
            'data' => [
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                ],
                'projects' => OrganizationPresenter::projects($projects, $context->tenantId),
            ],
        ]);
    }

    public function store(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manageProjects($request->user(), $context)) {
            return $this->forbidden('No autorizado para crear proyectos.');
        }

        $this->normalizeCodeInput($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'code' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', Rule::in(TenantProject::VALID_STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $project = $this->organization->saveProject($context, null, [
            'name' => trim($validated['name']),
            'code' => $validated['code'] ?? null,
            'status' => $validated['status'] ?? TenantProject::STATUS_ACTIVE,
            'description' => $validated['description'] ?? null,
        ], $correlation->id);

        return response()->json([
            'data' => OrganizationPresenter::project($project, $context->tenantId),
            'message' => 'Proyecto creado correctamente.',
        ], 201);
    }

    public function show(Request $request, string $project, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->view($request->user(), $context)) {
            return $this->forbidden('Acceso denegado.');
        }

        $row = $this->organization->getProject($context, $project, $correlation->id);
        if ($row === null) {
            return $this->notFound();
        }

        return response()->json(['data' => OrganizationPresenter::project($row, $context->tenantId)]);
    }

    public function update(Request $request, string $project, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manageProjects($request->user(), $context)) {
            return $this->forbidden('No autorizado para editar proyectos.');
        }

        $this->normalizeCodeInput($request);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'string', Rule::in(TenantProject::VALID_STATUSES)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        if (isset($validated['name'])) {
            $validated['name'] = trim($validated['name']);
        }

        $row = $validated === []
            ? $this->organization->getProject($context, $project, $correlation->id)
            : $this->organization->saveProject($context, $project, $validated, $correlation->id);
        if ($row === null) {
            return $this->notFound();
        }

        return response()->json([
            'data' => OrganizationPresenter::project($row, $context->tenantId),
            'message' => 'Proyecto actualizado correctamente.',
        ]);
    }

    public function destroy(Request $request, string $project, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manageProjects($request->user(), $context)) {
            return $this->forbidden('No autorizado para eliminar proyectos.');
        }

        if (! $this->organization->deleteProject($context, $project, $correlation->id)) {
            return $this->notFound();
        }

        return response()->json(['message' => 'Proyecto eliminado correctamente.']);
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
        return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Proyecto no encontrado.']], 404);
    }
}
