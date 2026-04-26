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
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Debe seleccionar un archivo.',
            'file.file' => 'El archivo enviado no es valido.',
            'file.mimes' => 'El archivo debe ser xlsx, xls o csv.',
            'file.max' => 'El archivo no puede superar 10MB.',
        ];
    }
}
