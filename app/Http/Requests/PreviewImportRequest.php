<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreviewImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:102400'],
            'sheet_index' => ['nullable', 'integer', 'min:0'],
            'sheet_name' => ['nullable', 'string', 'max:255'],
            'mapping' => ['nullable', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:100'],
            'mode' => ['nullable', 'string', 'max:50'],
            'profile_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Debe seleccionar un archivo.',
            'file.file' => 'El archivo enviado no es valido.',
            'file.mimes' => 'El archivo debe ser xlsx, xls o csv.',
            'file.max' => 'El archivo no puede superar 100MB.',
        ];
    }
}
