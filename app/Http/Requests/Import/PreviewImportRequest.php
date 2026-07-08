<?php

namespace App\Http\Requests\Import;

use App\Enums\CsvSourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('preview', \App\Models\CsvImport::class);
    }

    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255'],
            'source_type' => ['nullable', Rule::enum(CsvSourceType::class)],
            'strategy' => ['nullable', 'string', 'max:255'],
            'rows' => ['nullable', 'array'],
            'rows.*.hole_id' => ['required_with:rows', 'string'],
            'rows.*.length' => ['nullable', 'numeric'],
        ];
    }
}
