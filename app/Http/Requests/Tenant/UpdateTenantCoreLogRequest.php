<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantCoreLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controlado en controller/policy
    }

    public function rules(): array
    {
        return [
            'box_number' => ['nullable', 'string', 'max:64'],
            'geology_notes' => ['nullable', 'string', 'max:2000'],
            'photo_url' => ['nullable', 'string', 'max:500'],
            'recovered_meters' => ['sometimes', 'numeric', 'min:0'],
            'rqd_meters' => ['sometimes', 'numeric', 'min:0'],
            'logged_at' => ['nullable', 'date'],
        ];
    }
}
