<?php

namespace App\Http\Requests\Tenant;

use App\Enums\RoleInHole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantHoleAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controlado vía TenantHoleAssignmentPolicy
    }

    public function rules(): array
    {
        return [
            // Origen explícito opcional (si no se envía, se resuelve del contexto autorizado)
            'origin_id' => ['nullable', 'string', 'max:64'],

            // Formato cuadrilla completa (compatibilidad frontend web/móvil)
            'driller_id' => ['nullable', 'integer', 'exists:users,id'],
            'helper_id' => ['nullable', 'integer', 'exists:users,id'],
            'geologist_id' => ['nullable', 'integer', 'exists:users,id'],
            'supervisor_id' => ['nullable', 'integer', 'exists:users,id'],

            // Formato asignación individual
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'role_in_hole' => ['nullable', 'string', Rule::enum(RoleInHole::class)],
            'assigned_from' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'date', 'after_or_equal:assigned_from'],

            // Observaciones
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'driller_id.exists' => 'El perforista seleccionado no existe.',
            'helper_id.exists' => 'El ayudante seleccionado no existe.',
            'geologist_id.exists' => 'El geólogo seleccionado no existe.',
            'supervisor_id.exists' => 'El supervisor seleccionado no existe.',
            'user_id.exists' => 'El usuario seleccionado no existe.',
            'role_in_hole.Illuminate\Validation\Rules\Enum' => 'El rol operativo en el pozo no es válido.',
            'assigned_to.after_or_equal' => 'La fecha de fin de asignación debe ser posterior o igual a la de inicio.',
        ];
    }
}
