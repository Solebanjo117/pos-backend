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

    public function messages(): array
    {
        return [
            'name.required' => 'Debe asignar un nombre al perfil de importacion.',
            'name.string' => 'El nombre del perfil debe ser texto.',
            'name.max' => 'El nombre del perfil no puede superar 255 caracteres.',
            'mapping.array' => 'El mapeo del perfil debe enviarse como un objeto.',
            'sheet_index.integer' => 'La hoja del perfil debe ser un numero entero.',
            'sheet_index.min' => 'La hoja del perfil no puede ser negativa.',
            'sheet_name.string' => 'El nombre de la hoja debe ser texto.',
            'sheet_name.max' => 'El nombre de la hoja no puede superar 255 caracteres.',
            'mode.string' => 'El modo del perfil debe ser texto.',
            'mode.max' => 'El modo del perfil no puede superar 50 caracteres.',
            'grouping_rules.array' => 'Las reglas de agrupacion deben enviarse como un objeto.',
            'defaults.array' => 'Los valores por defecto deben enviarse como un objeto.',
        ];
    }
}
