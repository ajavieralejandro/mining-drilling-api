<?php

namespace App\Http\Requests\Observation;

use App\Enums\RiskLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRiskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('createRisk', $this->route('drillHole'));
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string'],
            'risk_level' => ['required', Rule::enum(RiskLevel::class)],
            'depth_detected' => ['nullable', 'numeric', 'min:0'],
            'critical_distance' => ['nullable', 'numeric', 'min:0'],
            'recommended_action' => ['nullable', 'string'],
        ];
    }
}
