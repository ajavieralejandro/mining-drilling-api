<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\DrillHole\StoreAssignmentRequest;
use App\Http\Resources\DrillHoleAssignmentResource;
use App\Models\DrillHole;
use App\Models\DrillHoleAssignment;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;

class DrillHoleAssignmentController extends Controller
{
    public function store(StoreAssignmentRequest $request, DrillHole $drillHole): JsonResponse
    {
        $assignment = DrillHoleAssignment::create([
            ...$request->validated(),
            'drill_hole_id' => $drillHole->id,
            'active' => true,
            'assigned_from' => $request->input('assigned_from', now()),
        ]);

        AuditLogger::log(
            $request->user(),
            DrillHoleAssignment::class,
            $assignment->id,
            'drill_hole.assignment_created',
            null,
            $assignment->toArray(),
            AuditSource::Web,
        );

        return (new DrillHoleAssignmentResource($assignment->load('user')))
            ->response()
            ->setStatusCode(201);
    }
}
