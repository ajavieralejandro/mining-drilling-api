<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTenantCoreLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controlado vía TenantHoleProgressPolicy::logCores
    }

    public function rules(): array
    {
        return [
            'run_from' => ['required', 'numeric', 'min:0'],
            'run_to' => ['required', 'numeric', 'gt:run_from'],
            'recovered_meters' => ['required', 'numeric', 'min:0'],
            'rqd_meters' => ['required', 'numeric', 'min:0'],
            'box_number' => ['nullable', 'string', 'max:64'],
            'logged_at' => ['nullable', 'date'],
            'geology_notes' => ['nullable', 'string', 'max:2000'],
            'origin_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'run_from.required' => 'La profundidad inicial de la corrida (run_from) es requerida.',
            'run_from.numeric' => 'La profundidad inicial debe ser numérica.',
            'run_to.required' => 'La profundidad final de la corrida (run_to) es requerida.',
            'run_to.gt' => 'La profundidad final de la corrida debe ser mayor que la inicial.',
            'recovered_meters.required' => 'Los metros recuperados de testigo son requeridos.',
            'recovered_meters.min' => 'Los metros recuperados no pueden ser negativos.',
            'rqd_meters.required' => 'Los metros de testigos sanos >= 10 cm (rqd_meters) son requeridos.',
            'rqd_meters.min' => 'Los metros de RQD no pueden ser negativos.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $data = $validator->getData();
                $runFrom = $data['run_from'] ?? null;
                $runTo = $data['run_to'] ?? null;
                $recovered = $data['recovered_meters'] ?? null;
                $rqd = $data['rqd_meters'] ?? null;

                if (is_numeric($runFrom) && is_numeric($runTo) && is_numeric($recovered)) {
                    $drillLength = bcsub(sprintf('%.2f', $runTo), sprintf('%.2f', $runFrom), 2);
                    if (bccomp(sprintf('%.2f', $recovered), $drillLength, 2) > 0) {
                        $validator->errors()->add(
                            'recovered_meters',
                            "Los metros recuperados ({$recovered} m) no pueden ser mayores que el largo de la corrida ({$drillLength} m)."
                        );
                    }
                }

                if (is_numeric($recovered) && is_numeric($rqd)) {
                    if (bccomp(sprintf('%.2f', $rqd), sprintf('%.2f', $recovered), 2) > 0) {
                        $validator->errors()->add(
                            'rqd_meters',
                            "Los metros de testigos sanos >= 10 cm ({$rqd} m) no pueden superar los metros recuperados totales ({$recovered} m)."
                        );
                    }
                }
            },
        ];
    }
}
