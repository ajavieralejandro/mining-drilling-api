<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DrillingPlanResource;
use App\Models\DrillingPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DrillingPlanController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', DrillingPlan::class);

        $query = DrillingPlan::query()->withCount(['platforms', 'drillHoles']);

        if (! $request->user()->canViewAll()) {
            $assignedIds = $request->user()->assignedDrillHoleIds();
            $query->whereHas('drillHoles', fn ($q) => $q->whereIn('id', $assignedIds));
        }

        return DrillingPlanResource::collection($query->orderBy('name')->get());
    }

    public function show(Request $request, DrillingPlan $drillingPlan): DrillingPlanResource
    {
        $this->authorize('view', $drillingPlan);

        $drillingPlan->load(['platforms', 'drillHoles', 'files']);

        return new DrillingPlanResource($drillingPlan);
    }
}
