<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantHoleProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controlado vía TenantHoleProgressPolicy
    }

    public function rules(): array
    {
        return [
            'depth_from' => ['required', 'numeric', 'min:0'],
            'depth_to' => ['required', 'numeric', 'min:0'],
            'shift' => ['nullable', 'string', 'in:A,B,C,a,b,c'],
            'logged_at' => ['nullable', 'date'],
            'observations' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
            'origin_id' => ['nullable', 'string', 'max:64'],
            'initial_depth' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'depth_from.required' => 'La profundidad inicial es requerida.',
            'depth_from.numeric' => 'La profundidad inicial debe ser un valor numérico.',
            'depth_from.min' => 'La profundidad inicial no puede ser negativa.',
            'depth_to.required' => 'La profundidad final es requerida.',
            'depth_to.numeric' => 'La profundidad final debe ser un valor numérico.',
            'depth_to.min' => 'La profundidad final no puede ser negativa.',
            'shift.in' => 'El turno debe ser A, B o C.',
            'idempotency_key.required' => 'La clave de idempotencia (idempotency_key) es obligatoria.',
            'idempotency_key.min' => 'La clave de idempotencia debe tener al menos 8 caracteres.',
            'idempotency_key.max' => 'La clave de idempotencia no puede superar 64 caracteres.',
        ];
    }
}
