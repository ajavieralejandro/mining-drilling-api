<?php

namespace App\Http\Requests\Observation;

use App\Enums\ObservationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('createObservation', $this->route('drillHole'));
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ObservationType::class)],
            'body' => ['required', 'string'],
        ];
    }
}
