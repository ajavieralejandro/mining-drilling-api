<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\TenantMineResource;
use App\Models\TenantMine;
use App\Models\TenantMineHole;
use App\Policies\TenantStructurePolicy;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TenantMineController extends Controller
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

        $query = TenantMine::query()
            ->where('tenant_id', $context->tenantId)
            ->with(['project:id,code,name,status'])
            ->withCount('holeLinks')
            ->orderBy('name');

        if ($request->filled('project_id')) {
            $query->where('project_id', (string) $request->query('project_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        if (in_array('holes', $includes, true)) {
            $query->with(['holeLinks' => fn ($q) => $q->orderBy('hole_code')->orderBy('hole_id')]);
        }

        $mines = $query->get();

        return response()->json([
            'data' => TenantMineResource::collection($mines),
            'total' => $mines->count(),
        ]);
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        if (! $this->policy->manageMines($request->user(), $context)) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'No autorizado para crear minas.'],
            ], 403);
        }

        $normalizedCode = $this->normalizeCode($request->input('code'));
        if ($normalizedCode !== $request->input('code')) {
            $request->merge(['code' => $normalizedCode]);
        }

        $validated = $request->validate([
            'project_id' => [
                'required',
                'string',
                Rule::exists('tenant_projects', 'id')
                    ->where('tenant_id', $context->tenantId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'code' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('tenant_mines', 'code')->where('tenant_id', $context->tenantId),
            ],
            'location' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', 'string', Rule::in(TenantMine::VALID_STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [
            'project_id.exists' => 'El proyecto indicado no pertenece a esta organización o no existe.',
        ]);

        $mine = TenantMine::create([
            'tenant_id' => $context->tenantId,
            'project_id' => $validated['project_id'],
            'code' => $validated['code'] ?? null,
            'name' => trim($validated['name']),
            'location' => isset($validated['location']) ? trim($validated['location']) : null,
            'status' => $validated['status'] ?? TenantMine::STATUS_ACTIVE,
            'description' => $validated['description'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $mine->load('project:id,code,name,status');
        $mine->loadCount('holeLinks');

        return (new TenantMineResource($mine))
            ->additional(['message' => 'Mina creada correctamente.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $mine, TenantContext $context): JsonResponse
    {
        if (! $this->policy->view($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado.']], 403);
        }

        $model = TenantMine::query()
            ->where('tenant_id', $context->tenantId)
            ->with([
                'project:id,code,name,status',
                'holeLinks' => fn ($q) => $q->orderBy('hole_code')->orderBy('hole_id'),
            ])
            ->withCount('holeLinks')
            ->find($mine);

        if (! $model) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Mina no encontrada.']], 404);
        }

        return (new TenantMineResource($model))->response();
    }

    public function update(Request $request, string $mine, TenantContext $context): JsonResponse
    {
        if (! $this->policy->manageMines($request->user(), $context)) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'No autorizado para editar minas.'],
            ], 403);
        }

        $model = TenantMine::query()
            ->where('tenant_id', $context->tenantId)
            ->find($mine);

        if (! $model) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Mina no encontrada.']], 404);
        }

        if ($request->has('code')) {
            $request->merge(['code' => $this->normalizeCode($request->input('code'))]);
        }

        $validated = $request->validate([
            'project_id' => [
                'sometimes',
                'required',
                'string',
                Rule::exists('tenant_projects', 'id')
                    ->where('tenant_id', $context->tenantId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'code' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
                Rule::unique('tenant_mines', 'code')
                    ->where('tenant_id', $context->tenantId)
                    ->ignore($model->id),
            ],
            'location' => ['sometimes', 'nullable', 'string', 'max:160'],
            'status' => ['sometimes', 'string', Rule::in(TenantMine::VALID_STATUSES)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ], [
            'project_id.exists' => 'El proyecto indicado no pertenece a esta organización o no existe.',
        ]);

        if (isset($validated['project_id'])) {
            $model->project_id = $validated['project_id'];
        }
        if (isset($validated['name'])) {
            $model->name = trim($validated['name']);
        }
        if (array_key_exists('code', $validated)) {
            $model->code = $validated['code'];
        }
        if (array_key_exists('location', $validated)) {
            $model->location = $validated['location'] !== null ? trim($validated['location']) : null;
        }
        if (isset($validated['status'])) {
            $model->status = $validated['status'];
        }
        if (array_key_exists('description', $validated)) {
            $model->description = $validated['description'];
        }

        $model->save();
        $model->load('project:id,code,name,status');
        $model->loadCount('holeLinks');

        return (new TenantMineResource($model))
            ->additional(['message' => 'Mina actualizada correctamente.'])
            ->response();
    }

    public function destroy(Request $request, string $mine, TenantContext $context): JsonResponse
    {
        if (! $this->policy->manageMines($request->user(), $context)) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'No autorizado para eliminar minas.'],
            ], 403);
        }

        $model = TenantMine::query()
            ->where('tenant_id', $context->tenantId)
            ->find($mine);

        if (! $model) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Mina no encontrada.']], 404);
        }

        DB::transaction(function () use ($model, $context) {
            TenantMineHole::query()
                ->where('tenant_id', $context->tenantId)
                ->where('mine_id', $model->id)
                ->delete();
            $model->delete();
        });

        return response()->json(['message' => 'Mina eliminada correctamente.']);
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
