<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DrillingPlatformResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'drilling_plan_id' => $this->drilling_plan_id,
            'code' => $this->code,
            'name' => $this->name,
            'easting' => $this->easting,
            'northing' => $this->northing,
            'elevation' => $this->elevation,
            'gallery' => $this->gallery,
            'level' => $this->level,
            'status' => $this->status?->value,
            'drill_holes' => DrillHoleResource::collection($this->whenLoaded('drillHoles')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
