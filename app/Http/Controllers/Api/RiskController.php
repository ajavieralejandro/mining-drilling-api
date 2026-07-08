<?php

namespace App\Http\Controllers\Api;

use App\Enums\ObservationType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ObservationResource;
use App\Models\Observation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RiskController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Observation::class);

        $query = Observation::query()
            ->where('type', ObservationType::Risk)
            ->with(['user', 'drillHole']);

        if (! $request->user()->canViewAll()) {
            $query->whereIn('drill_hole_id', $request->user()->assignedDrillHoleIds());
        }

        return ObservationResource::collection($query->latest()->get());
    }
}
