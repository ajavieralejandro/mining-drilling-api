<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DrillingPlatformResource;
use App\Models\DrillingPlan;
use App\Models\DrillingPlatform;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DrillingPlatformController extends Controller
{
    public function show(Request $request, DrillingPlatform $drillingPlatform): DrillingPlatformResource
    {
        $this->authorize('view', $drillingPlatform->plan);

        $drillingPlatform->load(['drillHoles', 'plan']);

        return new DrillingPlatformResource($drillingPlatform);
    }

    public function byPlan(Request $request, DrillingPlan $drillingPlan): AnonymousResourceCollection
    {
        $this->authorize('view', $drillingPlan);

        return DrillingPlatformResource::collection(
            $drillingPlan->platforms()->with('drillHoles')->orderBy('code')->get()
        );
    }
}
