<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class ReviewTenantHoleProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controlado vía TenantHoleProgressPolicy::review
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:approve,reject'],
            'review_notes' => ['nullable', 'string', 'max:2000', 'required_if:action,reject'],
        ];
    }

    public function messages(): array
    {
        return [
            'action.required' => 'La acción de revisión (approve o reject) es obligatoria.',
            'action.in' => 'La acción debe ser "approve" (aprobar) o "reject" (rechazar).',
            'review_notes.required_if' => 'El motivo de rechazo (review_notes) es obligatorio cuando se rechaza el parte diario.',
            'review_notes.max' => 'Las notas de revisión no pueden superar 2000 caracteres.',
        ];
    }
}
