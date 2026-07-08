<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MachineResource;
use App\Models\DrillingPlan;
use App\Models\Machine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MachineController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return MachineResource::collection(Machine::query()->orderBy('code')->get());
    }

    public function availableByPlan(Request $request, DrillingPlan $drillingPlan): AnonymousResourceCollection
    {
        $this->authorize('view', $drillingPlan);

        $machines = Machine::query()
            ->whereHas('availability', function ($q) use ($drillingPlan) {
                $q->where('drilling_plan_id', $drillingPlan->id)->where('active', true);
            })
            ->orWhere('status', 'active')
            ->orderBy('code')
            ->get();

        return MachineResource::collection($machines);
    }
}
