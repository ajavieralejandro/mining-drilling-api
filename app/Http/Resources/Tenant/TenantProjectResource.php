<?php

namespace App\Http\Resources\Tenant;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status,
            'description' => $this->description,
            'created_by' => $this->created_by,
            'mines_count' => $this->whenCounted('mines'),
            'holes_count' => $this->whenCounted('mineHoles'),
            'mines' => TenantMineResource::collection($this->whenLoaded('mines')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
