<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\DrillHole\StoreProgressRequest;
use App\Http\Resources\DrillHoleProgressLogResource;
use App\Models\DrillHole;
use App\Models\DrillHoleProgressLog;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;

class DrillHoleProgressController extends Controller
{
    public function store(StoreProgressRequest $request, DrillHole $drillHole): JsonResponse
    {
        $progress = DrillHoleProgressLog::create([
            ...$request->validated(),
            'drill_hole_id' => $drillHole->id,
            'user_id' => $request->user()->id,
            'logged_at' => $request->input('logged_at', now()),
        ]);

        $oldDepth = $drillHole->current_depth;
        $drillHole->update(['current_depth' => $progress->depth_current]);

        AuditLogger::log(
            $request->user(),
            DrillHoleProgressLog::class,
            $progress->id,
            'drill_hole.progress_logged',
            ['current_depth' => $oldDepth],
            ['current_depth' => $progress->depth_current, 'progress' => $progress->toArray()],
            AuditSource::Web,
        );

        return (new DrillHoleProgressLogResource($progress->load('user')))
            ->response()
            ->setStatusCode(201);
    }
}
