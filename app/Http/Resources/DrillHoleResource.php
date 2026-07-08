<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DrillHoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'drilling_plan_id' => $this->drilling_plan_id,
            'drilling_platform_id' => $this->drilling_platform_id,
            'order_number' => $this->order_number,
            'rec_id' => $this->rec_id,
            'hole_id' => $this->hole_id,
            'target' => $this->target,
            'length' => $this->length,
            'current_depth' => $this->current_depth,
            'azimuth' => $this->azimuth,
            'dip' => $this->dip,
            'hole_type' => $this->hole_type?->value,
            'status' => $this->status?->value,
            'easting' => $this->easting,
            'northing' => $this->northing,
            'elevation' => $this->elevation,
            'coordinate_system' => $this->coordinate_system,
            'observations' => $this->observations,
            'plan' => new DrillingPlanResource($this->whenLoaded('plan')),
            'platform' => new DrillingPlatformResource($this->whenLoaded('platform')),
            'assignments' => DrillHoleAssignmentResource::collection($this->whenLoaded('assignments')),
            'progress_logs' => DrillHoleProgressLogResource::collection($this->whenLoaded('progressLogs')),
            'hole_observations' => ObservationResource::collection($this->whenLoaded('holeObservations')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
