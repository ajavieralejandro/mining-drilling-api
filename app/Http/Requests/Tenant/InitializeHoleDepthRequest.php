<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class InitializeHoleDepthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controlado vía TenantHoleProgressPolicy
    }

    public function rules(): array
    {
        return [
            'initial_depth' => ['required', 'numeric', 'min:0'],
            'origin_id' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'initial_depth.required' => 'La profundidad inicial es requerida.',
            'initial_depth.numeric' => 'La profundidad inicial debe ser un valor numérico.',
            'initial_depth.min' => 'La profundidad inicial no puede ser negativa.',
        ];
    }
}
