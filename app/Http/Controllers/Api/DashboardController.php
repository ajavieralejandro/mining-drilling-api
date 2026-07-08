<?php

namespace App\Http\Controllers\Api;

use App\Enums\HoleStatus;
use App\Enums\ObservationType;
use App\Enums\RiskStatus;
use App\Http\Controllers\Controller;
use App\Models\DrillHole;
use App\Models\DrillingPlan;
use App\Models\Observation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $holesQuery = DrillHole::query();
        $plansQuery = DrillingPlan::query();

        if (! $user->canViewAll()) {
            $assignedIds = $user->assignedDrillHoleIds();
            $holesQuery->whereIn('id', $assignedIds);
            $plansQuery->whereHas('drillHoles', fn ($q) => $q->whereIn('id', $assignedIds));
        }

        return response()->json([
            'plans_total' => (clone $plansQuery)->count(),
            'holes_total' => (clone $holesQuery)->count(),
            'holes_in_progress' => (clone $holesQuery)->where('status', HoleStatus::InProgress)->count(),
            'open_risks' => Observation::query()
                ->where('type', ObservationType::Risk)
                ->where('risk_status', RiskStatus::Open)
                ->when(! $user->canViewAll(), fn ($q) => $q->whereIn('drill_hole_id', $user->assignedDrillHoleIds()))
                ->count(),
        ]);
    }
}
