<?php

namespace App\Http\Requests;

use App\Rules\ProductImportFile;
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
            'file' => ['required', 'file', 'max:102400', new ProductImportFile],
            'sheet_index' => ['nullable', 'integer', 'min:0'],
            'sheet_name' => ['nullable', 'string', 'max:255'],
            'mapping' => ['nullable', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:100'],
            'mode' => ['nullable', 'string', 'max:50'],
            'profile_id' => ['nullable', 'integer'],
            'import_run_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Debe seleccionar un archivo.',
            'file.file' => 'El archivo enviado no es valido.',
            'file.max' => 'El archivo no puede superar 100MB.',
            'sheet_index.integer' => 'La hoja debe ser un numero entero.',
            'sheet_index.min' => 'La hoja no puede ser negativa.',
            'sheet_name.string' => 'El nombre de la hoja debe ser texto.',
            'sheet_name.max' => 'El nombre de la hoja no puede superar 255 caracteres.',
            'mapping.array' => 'El mapeo debe enviarse como un objeto.',
            'mapping.*.string' => 'Cada columna del mapeo debe ser texto.',
            'mapping.*.max' => 'Cada columna del mapeo no puede superar 100 caracteres.',
            'mode.string' => 'El modo de importacion debe ser texto.',
            'mode.max' => 'El modo de importacion no puede superar 50 caracteres.',
            'profile_id.integer' => 'El perfil seleccionado no es valido.',
            'import_run_id.integer' => 'La previsualizacion seleccionada no es valida.',
        ];
    }
}
