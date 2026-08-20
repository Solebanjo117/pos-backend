<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'model_id' => ['required', 'integer', 'exists:vehicle_models,id'],
            'engine_id' => ['required', 'integer', 'exists:vehicle_engines,id'],
            'vins' => ['sometimes', 'array', 'max:100'],
            'vins.*.vin' => ['required', 'string', 'max:40'],
            'vins.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
