<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkDeleteVehicleCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity' => ['required', Rule::in(['makes', 'engines', 'models', 'vehicles'])],
            'ids' => ['required_unless:entity,vehicles', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
            'configurations' => ['required_if:entity,vehicles', 'array', 'min:1'],
            'configurations.*.model_id' => ['required', 'integer', 'exists:vehicle_models,id'],
            'configurations.*.engine_id' => ['required', 'integer', 'exists:vehicle_engines,id'],
        ];
    }
}
