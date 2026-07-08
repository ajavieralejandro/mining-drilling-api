<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DrillHoleProgressLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'drill_hole_id' => $this->drill_hole_id,
            'user_id' => $this->user_id,
            'depth_from' => $this->depth_from,
            'depth_to' => $this->depth_to,
            'depth_current' => $this->depth_current,
            'shift' => $this->shift,
            'logged_at' => $this->logged_at,
            'observations' => $this->observations,
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
