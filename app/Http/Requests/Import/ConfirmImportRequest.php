<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('confirm', \App\Models\CsvImport::class);
    }

    public function rules(): array
    {
        return [
            'import_id' => ['required', 'exists:csv_imports,id'],
        ];
    }
}
