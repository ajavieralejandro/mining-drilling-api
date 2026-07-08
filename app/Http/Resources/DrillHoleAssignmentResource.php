<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DrillHoleAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'drill_hole_id' => $this->drill_hole_id,
            'user_id' => $this->user_id,
            'role_in_hole' => $this->role_in_hole?->value,
            'assigned_from' => $this->assigned_from,
            'assigned_to' => $this->assigned_to,
            'active' => $this->active,
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
