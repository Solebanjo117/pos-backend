<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BatchProductImportVehicleCandidatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'candidates' => ['required', 'array', 'min:1'],
            'candidates.*' => ['required', 'array'],
            'candidates.*.make_id' => ['nullable', 'integer', 'exists:vehicle_makes,id'],
            'candidates.*.model_id' => ['nullable', 'integer', 'exists:vehicle_models,id'],
            'candidates.*.make' => ['nullable', 'string', 'max:100'],
            'candidates.*.model' => ['nullable', 'string', 'max:100'],
            'candidates.*.chassis' => ['nullable', 'string', 'max:100'],
            'candidates.*.engine' => ['nullable', 'string', 'max:100'],
            'candidates.*.label' => ['nullable', 'string', 'max:255'],
            'candidates.*.source' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'candidates.required' => 'Debe enviar vehiculos candidatos para crear.',
            'candidates.array' => 'Los vehiculos candidatos deben enviarse como una lista.',
            'candidates.min' => 'Debe enviar al menos un vehiculo candidato.',
            'candidates.*.array' => 'Cada vehiculo candidato debe enviarse como un objeto.',
            'candidates.*.make_id.integer' => 'La marca vehicular seleccionada no es valida.',
            'candidates.*.make_id.exists' => 'La marca vehicular seleccionada no existe.',
            'candidates.*.model_id.integer' => 'El modelo vehicular seleccionado no es valido.',
            'candidates.*.model_id.exists' => 'El modelo vehicular seleccionado no existe.',
            'candidates.*.make.string' => 'La marca vehicular debe ser texto.',
            'candidates.*.make.max' => 'La marca vehicular no puede superar 100 caracteres.',
            'candidates.*.model.string' => 'El modelo vehicular debe ser texto.',
            'candidates.*.model.max' => 'El modelo vehicular no puede superar 100 caracteres.',
            'candidates.*.chassis.string' => 'El chasis debe ser texto.',
            'candidates.*.chassis.max' => 'El chasis no puede superar 100 caracteres.',
            'candidates.*.engine.string' => 'El motor debe ser texto.',
            'candidates.*.engine.max' => 'El motor no puede superar 100 caracteres.',
            'candidates.*.label.string' => 'La etiqueta del vehiculo debe ser texto.',
            'candidates.*.label.max' => 'La etiqueta del vehiculo no puede superar 255 caracteres.',
            'candidates.*.source.string' => 'El origen del vehiculo debe ser texto.',
            'candidates.*.source.max' => 'El origen del vehiculo no puede superar 50 caracteres.',
        ];
    }
}
