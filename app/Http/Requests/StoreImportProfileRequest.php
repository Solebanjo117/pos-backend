<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreImportProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'mapping' => ['nullable', 'array'],
            'sheet_index' => ['nullable', 'integer', 'min:0'],
            'sheet_name' => ['nullable', 'string', 'max:255'],
            'mode' => ['nullable', 'string', 'max:50'],
            'grouping_rules' => ['nullable', 'array'],
            'defaults' => ['nullable', 'array'],
        ];
    }
}
