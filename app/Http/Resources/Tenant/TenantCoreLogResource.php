<?php

namespace App\Http\Resources\Tenant;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantCoreLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'origin_id' => $this->origin_id,
            'hole_id' => $this->hole_id,
            'box_number' => $this->box_number,
            'run_from' => sprintf('%.2f', $this->run_from),
            'run_to' => sprintf('%.2f', $this->run_to),
            'drill_length' => sprintf('%.2f', $this->drill_length),
            'recovered_meters' => sprintf('%.2f', $this->recovered_meters),
            'rqd_meters' => sprintf('%.2f', $this->rqd_meters),
            'rqd_percentage' => sprintf('%.2f', $this->rqd_percentage),
            'core_loss_meters' => sprintf('%.2f', $this->core_loss_meters),
            'geology_notes' => $this->geology_notes,
            'logged_by' => $this->logged_by,
            'logged_by_user' => new UserResource($this->whenLoaded('loggedByUser')),
            'logged_at' => $this->logged_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
