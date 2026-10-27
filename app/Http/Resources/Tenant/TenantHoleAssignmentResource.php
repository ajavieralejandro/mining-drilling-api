<?php

namespace App\Http\Resources\Tenant;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantHoleAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'operational_state_id' => $this->operational_state_id,
            'origin_id' => $this->origin_id,
            'hole_id' => $this->hole_id,
            'user_id' => $this->user_id,
            'role_in_hole' => $this->role_in_hole?->value ?? (string) $this->role_in_hole,
            'assigned_from' => $this->assigned_from?->toIso8601String(),
            'assigned_to' => $this->assigned_to?->toIso8601String(),
            'active' => (bool) $this->active,
            'notes' => $this->notes,
            'user' => new UserResource($this->whenLoaded('user')),
            'assigned_by' => new UserResource($this->whenLoaded('assignedBy')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
