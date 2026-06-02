<?php

namespace App\Http\Requests;

use App\Models\ImportRun;
use App\Rules\ProductImportFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'validated_import_run_id' => ['nullable', 'integer'],
            'file' => ['nullable', 'file', 'max:102400', new ProductImportFile],
            'sheet_index' => ['nullable', 'integer', 'min:0'],
            'sheet_name' => ['nullable', 'string', 'max:255'],
            'mapping' => ['nullable', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:100'],
            'mode' => ['nullable', 'string', Rule::in([
                ImportRun::MODE_CREATE_UPDATE,
                ImportRun::MODE_CREATE_ONLY,
                ImportRun::MODE_UPDATE_STOCK_PRICE,
            ])],
            'profile_id' => ['nullable', 'integer'],
            'force_without_preview' => ['nullable', 'boolean'],
            'force_confirmation' => ['nullable', 'string', 'max:100'],
            'force_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.file' => 'El archivo enviado no es valido.',
            'file.max' => 'El archivo no puede superar 100MB.',
            'validated_import_run_id.integer' => 'La validacion seleccionada no es valida.',
            'sheet_index.integer' => 'La hoja debe ser un numero entero.',
            'sheet_index.min' => 'La hoja no puede ser negativa.',
            'sheet_name.string' => 'El nombre de la hoja debe ser texto.',
            'sheet_name.max' => 'El nombre de la hoja no puede superar 255 caracteres.',
            'mapping.array' => 'El mapeo debe enviarse como un objeto.',
            'mapping.*.string' => 'Cada columna del mapeo debe ser texto.',
            'mapping.*.max' => 'Cada columna del mapeo no puede superar 100 caracteres.',
            'mode.in' => 'El modo de importacion seleccionado no es valido.',
            'profile_id.integer' => 'El perfil seleccionado no es valido.',
            'force_without_preview.boolean' => 'La confirmacion avanzada debe ser verdadera o falsa.',
            'force_confirmation.max' => 'La confirmacion avanzada no puede superar 100 caracteres.',
            'force_reason.max' => 'El motivo no puede superar 500 caracteres.',
        ];
    }
}
