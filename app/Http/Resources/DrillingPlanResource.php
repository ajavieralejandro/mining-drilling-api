<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DrillingPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mine' => $this->mine,
            'level' => $this->level,
            'sector' => $this->sector,
            'purpose' => $this->purpose?->value,
            'planned_meters' => $this->planned_meters,
            'executed_meters' => $this->executed_meters,
            'status' => $this->status?->value,
            'description' => $this->description,
            'created_by' => $this->created_by,
            'platforms_count' => $this->whenCounted('platforms'),
            'drill_holes_count' => $this->whenCounted('drillHoles'),
            'platforms' => DrillingPlatformResource::collection($this->whenLoaded('platforms')),
            'drill_holes' => DrillHoleResource::collection($this->whenLoaded('drillHoles')),
            'files' => PlanFileResource::collection($this->whenLoaded('files')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
