<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ObservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'drill_hole_id' => $this->drill_hole_id,
            'user_id' => $this->user_id,
            'type' => $this->type?->value,
            'body' => $this->body,
            'risk_level' => $this->risk_level?->value,
            'risk_status' => $this->risk_status?->value,
            'depth_detected' => $this->depth_detected,
            'critical_distance' => $this->critical_distance,
            'recommended_action' => $this->recommended_action,
            'closed_at' => $this->closed_at,
            'reviewed_by' => $this->reviewed_by,
            'user' => new UserResource($this->whenLoaded('user')),
            'drill_hole' => new DrillHoleResource($this->whenLoaded('drillHole')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
