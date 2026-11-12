<?php

namespace App\Http\Resources\Tenant;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantMineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'project_id' => $this->project_id,
            'code' => $this->code,
            'name' => $this->name,
            'location' => $this->location,
            'status' => $this->status,
            'description' => $this->description,
            'created_by' => $this->created_by,
            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
                'status' => $this->project->status,
            ] : null),
            'holes_count' => $this->whenCounted('holeLinks'),
            'holes' => TenantMineHoleResource::collection($this->whenLoaded('holeLinks')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
