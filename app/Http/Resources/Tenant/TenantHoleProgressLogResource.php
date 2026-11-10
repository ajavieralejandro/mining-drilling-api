<?php

namespace App\Http\Resources\Tenant;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantHoleProgressLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $depthFrom = sprintf('%.2f', $this->depth_from);
        $depthTo = sprintf('%.2f', $this->depth_to);
        $depthCurrent = sprintf('%.2f', $this->depth_current);

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'operational_state_id' => $this->operational_state_id,
            'origin_id' => $this->origin_id,
            'hole_id' => $this->hole_id,
            'user_id' => $this->user_id,
            'depth_from' => $depthFrom,
            'depth_to' => $depthTo,
            'depth_current' => $depthCurrent,
            'advance_meters' => bcsub($depthTo, $depthFrom, 2),
            'shift' => $this->shift,
            'hours_worked' => $this->hours_worked !== null ? sprintf('%.2f', $this->hours_worked) : null,
            'status' => $this->status ?? 'pending_review',
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_notes' => $this->review_notes,
            'idempotency_key' => $this->idempotency_key,
            'crew_snapshot' => $this->crew_snapshot,
            'logged_at' => $this->logged_at?->toIso8601String(),
            'observations' => $this->observations,
            'user' => new UserResource($this->whenLoaded('user')),
            'reviewer' => new UserResource($this->whenLoaded('reviewer')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
