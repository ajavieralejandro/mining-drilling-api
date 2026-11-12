<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\TenantProjectResource;
use App\Models\Tenant;
use App\Models\TenantMineHole;
use App\Models\TenantProject;
use App\Policies\TenantStructurePolicy;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TenantProjectController extends Controller
{
    public function __construct(
        private readonly TenantStructurePolicy $policy,
    ) {}

    public function index(Request $request, TenantContext $context): JsonResponse
    {
        if (! $this->policy->view($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado.']], 403);
        }

        $includes = collect(explode(',', (string) $request->query('include', '')))
            ->map(fn (string $v) => trim($v))
            ->filter()
            ->all();

        $query = TenantProject::query()
            ->where('tenant_id', $context->tenantId)
            ->withCount(['mines', 'mineHoles'])
            ->orderBy('name');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if (in_array('mines', $includes, true) || in_array('holes', $includes, true)) {
            $query->with(['mines' => function ($q) use ($includes) {
                $q->withCount('holeLinks')->orderBy('name');
                if (in_array('holes', $includes, true)) {
                    $q->with(['holeLinks' => fn ($hq) => $hq->orderBy('hole_code')->orderBy('hole_id')]);
                }
            }]);
        }

        $projects = $query->get();

        return response()->json([
            'data' => TenantProjectResource::collection($projects),
            'total' => $projects->count(),
        ]);
    }

    public function hierarchy(Request $request, TenantContext $context): JsonResponse
    {
        if (! $this->policy->view($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado.']], 403);
        }

        $tenant = Tenant::query()->findOrFail($context->tenantId);

        $projects = TenantProject::query()
            ->where('tenant_id', $context->tenantId)
            ->withCount(['mines', 'mineHoles'])
            ->with(['mines' => function ($q) {
                $q->withCount('holeLinks')
                    ->with(['holeLinks' => fn ($hq) => $hq->orderBy('hole_code')->orderBy('hole_id')])
                    ->orderBy('name');
            }])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => [
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                ],
                'projects' => TenantProjectResource::collection($projects),
            ],
        ]);
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        if (! $this->policy->manageProjects($request->user(), $context)) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'No autorizado para crear proyectos.'],
            ], 403);
        }

        $normalizedCode = $this->normalizeCode($request->input('code'));
        if ($normalizedCode !== $request->input('code')) {
            $request->merge(['code' => $normalizedCode]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'code' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('tenant_projects', 'code')->where('tenant_id', $context->tenantId),
            ],
            'status' => ['nullable', 'string', Rule::in(TenantProject::VALID_STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $project = TenantProject::create([
            'tenant_id' => $context->tenantId,
            'code' => $validated['code'] ?? null,
            'name' => trim($validated['name']),
            'status' => $validated['status'] ?? TenantProject::STATUS_ACTIVE,
            'description' => $validated['description'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $project->loadCount(['mines', 'mineHoles']);

        return (new TenantProjectResource($project))
            ->additional(['message' => 'Proyecto creado correctamente.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $project, TenantContext $context): JsonResponse
    {
        if (! $this->policy->view($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado.']], 403);
        }

        $model = TenantProject::query()
            ->where('tenant_id', $context->tenantId)
            ->withCount(['mines', 'mineHoles'])
            ->with(['mines' => function ($q) {
                $q->withCount('holeLinks')
                    ->with(['holeLinks' => fn ($hq) => $hq->orderBy('hole_code')->orderBy('hole_id')])
                    ->orderBy('name');
            }])
            ->find($project);

        if (! $model) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Proyecto no encontrado.']], 404);
        }

        return (new TenantProjectResource($model))->response();
    }

    public function update(Request $request, string $project, TenantContext $context): JsonResponse
    {
        if (! $this->policy->manageProjects($request->user(), $context)) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'No autorizado para editar proyectos.'],
            ], 403);
        }

        $model = TenantProject::query()
            ->where('tenant_id', $context->tenantId)
            ->find($project);

        if (! $model) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Proyecto no encontrado.']], 404);
        }

        if ($request->has('code')) {
            $request->merge(['code' => $this->normalizeCode($request->input('code'))]);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'code' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
                Rule::unique('tenant_projects', 'code')
                    ->where('tenant_id', $context->tenantId)
                    ->ignore($model->id),
            ],
            'status' => ['sometimes', 'string', Rule::in(TenantProject::VALID_STATUSES)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if (isset($validated['name'])) {
            $model->name = trim($validated['name']);
        }
        if (array_key_exists('code', $validated)) {
            $model->code = $validated['code'];
        }
        if (isset($validated['status'])) {
            $model->status = $validated['status'];
        }
        if (array_key_exists('description', $validated)) {
            $model->description = $validated['description'];
        }

        $model->save();
        $model->loadCount(['mines', 'mineHoles']);

        return (new TenantProjectResource($model))
            ->additional(['message' => 'Proyecto actualizado correctamente.'])
            ->response();
    }

    public function destroy(Request $request, string $project, TenantContext $context): JsonResponse
    {
        if (! $this->policy->manageProjects($request->user(), $context)) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'No autorizado para eliminar proyectos.'],
            ], 403);
        }

        $model = TenantProject::query()
            ->where('tenant_id', $context->tenantId)
            ->find($project);

        if (! $model) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Proyecto no encontrado.']], 404);
        }

        DB::transaction(function () use ($model, $context) {
            $mineIds = $model->mines()->pluck('id')->all();
            if (! empty($mineIds)) {
                TenantMineHole::query()
                    ->where('tenant_id', $context->tenantId)
                    ->whereIn('mine_id', $mineIds)
                    ->delete();
                $model->mines()->delete();
            }
            $model->delete();
        });

        return response()->json(['message' => 'Proyecto eliminado correctamente.']);
    }

    private function normalizeCode(mixed $code): ?string
    {
        if (! is_string($code)) {
            return null;
        }
        $trimmed = trim($code);

        return $trimmed === '' ? null : strtoupper($trimmed);
    }
}
