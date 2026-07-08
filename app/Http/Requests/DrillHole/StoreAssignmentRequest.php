<?php

namespace App\Http\Requests\DrillHole;

use App\Enums\RoleInHole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assignPersonnel', $this->route('drillHole'));
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'role_in_hole' => ['required', Rule::enum(RoleInHole::class)],
            'assigned_from' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'date', 'after_or_equal:assigned_from'],
        ];
    }
}
