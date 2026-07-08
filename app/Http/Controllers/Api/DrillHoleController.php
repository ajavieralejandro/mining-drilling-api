<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\DrillHole\StoreDrillHoleRequest;
use App\Http\Requests\DrillHole\UpdateTechnicalDataRequest;
use App\Http\Resources\DrillHoleResource;
use App\Models\DrillHole;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DrillHoleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', DrillHole::class);

        $query = DrillHole::query()->with(['plan', 'platform']);

        if (! $request->user()->canViewAll()) {
            $query->whereIn('id', $request->user()->assignedDrillHoleIds());
        }

        return DrillHoleResource::collection($query->orderBy('hole_id')->get());
    }

    public function show(Request $request, DrillHole $drillHole): DrillHoleResource
    {
        $this->authorize('view', $drillHole);

        $drillHole->load(['plan', 'platform', 'assignments.user', 'progressLogs', 'holeObservations']);

        return new DrillHoleResource($drillHole);
    }

    public function store(StoreDrillHoleRequest $request): JsonResponse
    {
        $drillHole = DrillHole::create($request->validated());

        AuditLogger::log(
            $request->user(),
            DrillHole::class,
            $drillHole->id,
            'drill_hole.created',
            null,
            $drillHole->toArray(),
            AuditSource::Web,
        );

        return (new DrillHoleResource($drillHole->load(['plan', 'platform'])))
            ->response()
            ->setStatusCode(201);
    }

    public function updateTechnicalData(UpdateTechnicalDataRequest $request, DrillHole $drillHole): DrillHoleResource
    {
        $oldValues = $drillHole->only(array_keys($request->validated()));
        $drillHole->update($request->validated());

        AuditLogger::log(
            $request->user(),
            DrillHole::class,
            $drillHole->id,
            'drill_hole.technical_data_updated',
            $oldValues,
            $drillHole->fresh()->toArray(),
            AuditSource::Web,
        );

        return new DrillHoleResource($drillHole->fresh()->load(['plan', 'platform']));
    }
}
