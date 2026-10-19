<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Source\SourceConfigService;
use App\Support\GatewayException;
use App\Support\RequestCorrelation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantSourceConfigController extends Controller
{
    public function __construct(private readonly SourceConfigService $configs) {}

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        try {
            $config = $this->configs->save($context, $request->all());
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'data' => $config,
            'pending' => true,
            'applied' => false,
        ], 201);
    }

    public function cancel(TenantContext $context): JsonResponse
    {
        try {
            $this->configs->cancel($context);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        return response()->json(['data' => ['cancelled' => true, 'applied' => false]]);
    }

    public function test(TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        try {
            $config = $this->configs->test($context, $correlation->id);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        return response()->json(['data' => $config, 'applied' => false]);
    }

    public function apply(Request $request, TenantContext $context, RequestCorrelation $correlation): JsonResponse
    {
        try {
            $config = $this->configs->apply($context, $request->boolean('confirm'), $correlation->id);
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        return response()->json(['data' => $config, 'applied' => $config['state'] === 'active']);
    }
}
