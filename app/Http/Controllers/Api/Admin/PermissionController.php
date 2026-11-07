<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        $permissions = Permission::orderBy('module')->orderBy('name')->get();

        $byModule = $permissions->groupBy('module');

        return response()->json([
            'data' => $permissions,
            'modules' => $byModule,
            'total' => $permissions->count(),
        ]);
    }
}
