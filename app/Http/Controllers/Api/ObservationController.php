<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Observation\CloseRiskRequest;
use App\Http\Requests\Observation\StoreObservationRequest;
use App\Http\Requests\Observation\StoreRiskRequest;
use App\Http\Resources\ObservationResource;
use App\Models\DrillHole;
use App\Models\Observation;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ObservationController extends Controller
{
    public function index(Request $request, DrillHole $drillHole): AnonymousResourceCollection
    {
        $this->authorize('view', $drillHole);

        return ObservationResource::collection(
            $drillHole->holeObservations()->with('user')->latest()->get()
        );
    }

    public function store(StoreObservationRequest $request, DrillHole $drillHole): JsonResponse
    {
        $observation = Observation::create([
            ...$request->validated(),
            'drill_hole_id' => $drillHole->id,
            'user_id' => $request->user()->id,
        ]);

        AuditLogger::log(
            $request->user(),
            Observation::class,
            $observation->id,
            'observation.created',
            null,
            $observation->toArray(),
            AuditSource::Web,
        );

        return (new ObservationResource($observation->load('user')))
            ->response()
            ->setStatusCode(201);
    }

    public function storeRisk(StoreRiskRequest $request, DrillHole $drillHole): JsonResponse
    {
        $observation = Observation::create([
            'drill_hole_id' => $drillHole->id,
            'user_id' => $request->user()->id,
            'type' => 'risk',
            'body' => $request->input('body'),
            'risk_level' => $request->input('risk_level'),
            'risk_status' => 'open',
            'depth_detected' => $request->input('depth_detected'),
            'critical_distance' => $request->input('critical_distance'),
            'recommended_action' => $request->input('recommended_action'),
        ]);

        AuditLogger::log(
            $request->user(),
            Observation::class,
            $observation->id,
            'risk.reported',
            null,
            $observation->toArray(),
            AuditSource::Web,
        );

        return (new ObservationResource($observation->load('user')))
            ->response()
            ->setStatusCode(201);
    }

    public function closeRisk(CloseRiskRequest $request, Observation $observation): ObservationResource
    {
        $oldValues = $observation->only(['risk_status', 'closed_at', 'reviewed_by']);

        $observation->update([
            'risk_status' => $request->input('risk_status', 'closed'),
            'closed_at' => now(),
            'reviewed_by' => $request->user()->id,
        ]);

        AuditLogger::log(
            $request->user(),
            Observation::class,
            $observation->id,
            'risk.closed',
            $oldValues,
            $observation->fresh()->only(['risk_status', 'closed_at', 'reviewed_by']),
            AuditSource::Web,
        );

        return new ObservationResource($observation->fresh()->load('user'));
    }
}
