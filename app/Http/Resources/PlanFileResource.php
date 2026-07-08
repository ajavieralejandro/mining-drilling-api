<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'drilling_plan_id' => $this->drilling_plan_id,
            'name' => $this->name,
            'type' => $this->type?->value,
            'version' => $this->version,
            'description' => $this->description,
            'file_path' => $this->file_path,
            'uploaded_by' => $this->uploaded_by,
        ];
    }
}
