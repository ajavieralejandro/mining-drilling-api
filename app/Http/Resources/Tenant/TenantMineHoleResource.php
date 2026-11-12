<?php

namespace App\Http\Resources\Tenant;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantMineHoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'mine_id' => $this->mine_id,
            'origin_id' => $this->origin_id,
            'hole_id' => $this->hole_id,
            'hole_code' => $this->hole_code ?: $this->hole_id,
            'drill_hole_id' => $this->drill_hole_id,
            'status' => $this->status,
            'notes' => $this->notes,
            'assigned_by' => $this->assigned_by,
            'mine' => $this->whenLoaded('mine', fn () => $this->mine ? [
                'id' => $this->mine->id,
                'code' => $this->mine->code,
                'name' => $this->mine->name,
                'location' => $this->mine->location,
                'project_id' => $this->mine->project_id,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
