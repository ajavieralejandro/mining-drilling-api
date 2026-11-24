<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Repositories\HoleRegistry;
use App\Http\Controllers\Controller;
use App\Policies\TenantHolePolicy;
use App\Support\RequestCorrelation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Registers and edits holes from UndSurf, in the client database. The
 * connector checks unique codes, the mine, status changes and that the
 * planned depth is not below what is already drilled. The company's own
 * holes cannot be edited here.
 */
class TenantHoleRegistrationController extends Controller
{
    private const STATUSES = ['planned', 'in_progress', 'paused', 'completed', 'aborted'];

    private const DECIMAL = 'regex:/^-?\d+(\.\d{1,2})?$/';

    public function __construct(
        private readonly HoleRegistry $holes,
        private readonly TenantHolePolicy $policy,
    ) {}

    public function show(Request $request, string $hole, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        $row = $this->holes->find($context, $hole, $correlation->id);

        return $row === null ? $this->notFound() : response()->json([
            'data' => $this->present($row),
            'can_update' => $this->policy->update($request->user(), $context),
        ]);
    }

    public function store(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->create($request->user(), $context)) {
            return $this->forbidden('No autorizado para registrar pozos.');
        }
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'status' => ['sometimes', Rule::in(['planned', 'in_progress'])],
            'mine_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ] + $this->technicalRules());

        return response()->json([
            'data' => $this->present($this->holes->save($context, null, $this->fields($validated), $correlation->id) ?? []),
            'message' => 'Pozo registrado.',
        ], 201);
    }

    public function update(Request $request, string $hole, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->update($request->user(), $context)) {
            return $this->forbidden('No autorizado para editar pozos.');
        }
        $validated = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:64'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
        ] + $this->technicalRules());

        $row = $this->holes->save($context, $hole, $this->fields($validated), $correlation->id);

        return $row === null ? $this->notFound() : response()->json([
            'data' => $this->present($row),
            'message' => 'Pozo actualizado.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function technicalRules(): array
    {
        return [
            'hole_type' => ['sometimes', Rule::in(['underground', 'surface'])],
            'location' => ['sometimes', 'nullable', 'string', 'max:160'],
            'planned_depth' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:10000', self::DECIMAL],
            'azimuth' => ['sometimes', 'nullable', 'numeric', 'between:0,360', self::DECIMAL],
            'dip' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', self::DECIMAL],
            'easting' => ['sometimes', 'nullable', 'numeric', 'between:-99999999,99999999', self::DECIMAL],
            'northing' => ['sometimes', 'nullable', 'numeric', 'between:-99999999,99999999', self::DECIMAL],
            'elevation' => ['sometimes', 'nullable', 'numeric', 'between:-99999,99999', self::DECIMAL],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
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
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $by = $row['created_by'] ?? null;
        $row['created_by'] = ctype_digit((string) ($by ?? '')) ? (int) $by : $by;

        return $row;
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => $message]], 403);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Pozo no registrado desde UndSurf.']], 404);
    }
}
