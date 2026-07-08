<?php

namespace App\Http\Requests\Observation;

use App\Enums\RiskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CloseRiskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('closeRisk', $this->route('observation'));
    }

    public function rules(): array
    {
        return [
            'risk_status' => ['nullable', Rule::enum(RiskStatus::class)],
        ];
    }
}
