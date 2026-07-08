<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CsvImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'imported_by' => $this->imported_by,
            'status' => $this->status?->value,
            'total_rows' => $this->total_rows,
            'valid_rows' => $this->valid_rows,
            'invalid_rows' => $this->invalid_rows,
            'errors' => $this->errors,
            'strategy' => $this->strategy,
            'source_type' => $this->source_type?->value,
            'created_at' => $this->created_at,
        ];
    }
}
