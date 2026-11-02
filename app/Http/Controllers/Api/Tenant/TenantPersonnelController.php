<?php

namespace App\Services\Tenant;

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveRequestCorrelation;
use App\Http\Middleware\ResolveTenantContext;
use App\Policies\TenantPersonnelPolicy;
use App\Services\Tenant\TenantPersonnelService;
use App\Support\RequestCorrelation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantPersonnelController extends Controller
{
    public function __construct(
        private readonly TenantPersonnelService $service,
        private readonly TenantPersonnelPolicy $policy,
    ) {}

    public function index(Request $request, TenantContext $context): JsonResponse
    {
        if (! $this->policy->viewAny($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Acceso denegado']], 403);
        }

        $list = $this->service->list($context);

        return response()->json([
            'data' => $list,
            'total' => count($list),
        ]);
    }

    public function store(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manage($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Solo administradores o supervisores pueden registrar personal']], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'email' => ['required', 'email', 'max:128'],
            'role' => ['required', 'string', Rule::in(['driller', 'helper', 'geologist', 'supervisor', 'geotechnical', 'admin'])],
            'external_hr_id' => ['nullable', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        try {
            $result = $this->service->create($context, $request->user(), $validated, $correlation->id);

            return response()->json([
                'message' => 'Integrante registrado exitosamente.',
                'data' => $result['member'],
                'temporary_password' => $result['temporary_password'],
                'connector_synced' => $result['connector_synced'],
                'sync_message' => $result['sync_message'],
            ], 201);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'MEMBERSHIP_ALREADY_ACTIVE') {
                return response()->json([
                    'error' => [
                        'code' => 'MEMBERSHIP_ALREADY_ACTIVE',
                        'message' => 'El usuario ya cuenta con una membresía activa en otra organización.',
                    ],
                ], 422);
            }
            throw $e;
        }
    }

    public function update(Request $request, int $id, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manage($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Solo administradores o supervisores pueden modificar personal']], 403);
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:128'],
            'role' => ['nullable', 'string', Rule::in(['driller', 'helper', 'geologist', 'supervisor', 'geotechnical', 'admin'])],
            'external_hr_id' => ['nullable', 'string', 'max:64'],
        ]);

        $result = $this->service->update($context, $request->user(), $id, $validated, $correlation->id);

        return response()->json([
            'message' => 'Integrante actualizado exitosamente.',
            'data' => $result['member'],
            'connector_synced' => $result['connector_synced'],
            'sync_message' => $result['sync_message'],
        ]);
    }

    public function destroy(Request $request, int $id, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        if (! $this->policy->manage($request->user(), $context)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Solo administradores o supervisores pueden dar de baja personal']], 403);
        }

        $reason = $request->input('reason', 'Baja solicitada por administrador');

        $result = $this->service->deactivate($context, $request->user(), $id, (string) $reason, $correlation->id);

        return response()->json([
            'message' => 'Membresía dada de baja y tokens revocados exitosamente.',
            'data' => $result,
            'connector_synced' => $result['connector_synced'],
            'sync_message' => $result['sync_message'],
        ]);
    }
}
