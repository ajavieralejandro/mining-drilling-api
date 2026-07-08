<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanFileResource;
use App\Models\DrillingPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlanFileController extends Controller
{
    public function byPlan(Request $request, DrillingPlan $drillingPlan): AnonymousResourceCollection
    {
        $this->authorize('view', $drillingPlan);

        return PlanFileResource::collection(
            $drillingPlan->files()->orderBy('name')->get()
        );
    }
}
