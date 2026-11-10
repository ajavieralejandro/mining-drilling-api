<?php

namespace App\Http\Requests\Tenant;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'hours_worked' => ['required', 'numeric', 'min:0.1', 'max:8.0'],
            'shift' => ['nullable', 'string', 'in:1,2,Turno 1,Turno 2,A,B,C,a,b,c,shift_1,shift_2'],
            'logged_at' => ['nullable', 'date'],
            'observations' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
            'origin_id' => ['nullable', 'string', 'max:64'],
            'initial_depth' => ['nullable', 'numeric', 'min:0'],
            'blasting_window_bypass' => ['nullable', 'boolean'],
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
            'hours_worked.required' => 'Las horas trabajadas en el turno son obligatorias.',
            'hours_worked.numeric' => 'Las horas trabajadas deben ser un valor numérico.',
            'hours_worked.min' => 'Las horas trabajadas deben ser de al menos 0.1 horas.',
            'hours_worked.max' => 'La jornada minera máxima por turno dentro de mina es de 8 horas.',
            'shift.in' => 'El turno debe ser Turno 1, Turno 2 (o A / B).',
            'idempotency_key.required' => 'La clave de idempotencia (idempotency_key) es obligatoria.',
            'idempotency_key.min' => 'La clave de idempotencia debe tener al menos 8 caracteres.',
            'idempotency_key.max' => 'La clave de idempotencia no puede superar 64 caracteres.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $data = $validator->getData();

                // Validación de Ventana de Voladuras y Ventilación (00:00 - 08:00)
                // Durante estas 8 horas está prohibido el ingreso a interior mina para perforación
                if (!empty($data['blasting_window_bypass'])) {
                    return;
                }

                if (!empty($data['logged_at'])) {
                    $timeToCheck = Carbon::parse($data['logged_at']);
                    $hour = $timeToCheck->hour;
                    if ($hour >= 0 && $hour < 8) {
                        $validator->errors()->add(
                            'logged_at',
                            'Ventana de voladura y ventilación activa (00:00 - 08:00). Prohibido el ingreso a interior mina y registro de avance en este período.'
                        );
                    }
                }
            },
        ];
    }
}
