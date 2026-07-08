<?php

namespace App\Http\Requests\DrillHole;

use App\Enums\HoleStatus;
use App\Enums\HoleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTechnicalDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateTechnicalData', $this->route('drillHole'));
    }

    public function rules(): array
    {
        return [
            'target' => ['sometimes', 'nullable', 'string', 'max:255'],
            'length' => ['sometimes', 'numeric', 'min:0'],
            'azimuth' => ['sometimes', 'numeric'],
            'dip' => ['sometimes', 'numeric'],
            'hole_type' => ['sometimes', 'nullable', Rule::enum(HoleType::class)],
            'status' => ['sometimes', Rule::enum(HoleStatus::class)],
            'easting' => ['sometimes', 'nullable', 'numeric'],
            'northing' => ['sometimes', 'nullable', 'numeric'],
            'elevation' => ['sometimes', 'nullable', 'numeric'],
            'coordinate_system' => ['sometimes', 'nullable', 'string', 'max:255'],
            'observations' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
