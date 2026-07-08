<?php

namespace App\Http\Requests\DrillHole;

use App\Enums\HoleStatus;
use App\Enums\HoleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDrillHoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\DrillHole::class);
    }

    public function rules(): array
    {
        return [
            'drilling_plan_id' => ['nullable', 'exists:drilling_plans,id'],
            'drilling_platform_id' => ['nullable', 'exists:drilling_platforms,id'],
            'order_number' => ['nullable', 'integer'],
            'rec_id' => ['nullable', 'string', 'max:255'],
            'hole_id' => ['nullable', 'string', 'max:255', 'unique:drill_holes,hole_id'],
            'target' => ['nullable', 'string', 'max:255'],
            'length' => ['required', 'numeric', 'min:0'],
            'azimuth' => ['required', 'numeric'],
            'dip' => ['required', 'numeric'],
            'hole_type' => ['nullable', Rule::enum(HoleType::class)],
            'status' => ['nullable', Rule::enum(HoleStatus::class)],
            'easting' => ['nullable', 'numeric'],
            'northing' => ['nullable', 'numeric'],
            'elevation' => ['nullable', 'numeric'],
            'coordinate_system' => ['nullable', 'string', 'max:255'],
            'observations' => ['nullable', 'string'],
        ];
    }
}
