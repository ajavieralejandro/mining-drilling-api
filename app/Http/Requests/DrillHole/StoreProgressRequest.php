<?php

namespace App\Http\Requests\DrillHole;

use Illuminate\Foundation\Http\FormRequest;

class StoreProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('logProgress', $this->route('drillHole'));
    }

    public function rules(): array
    {
        return [
            'depth_from' => ['required', 'numeric', 'min:0'],
            'depth_to' => ['required', 'numeric', 'gte:depth_from'],
            'depth_current' => ['required', 'numeric', 'min:0'],
            'shift' => ['nullable', 'string', 'max:255'],
            'logged_at' => ['nullable', 'date'],
            'observations' => ['nullable', 'string'],
        ];
    }
}
