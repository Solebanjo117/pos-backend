<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegenerateProductNameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => 'sometimes|nullable|exists:categories,id',
            'extra_category_ids' => 'sometimes|array',
            'extra_category_ids.*' => 'integer|distinct|exists:categories,id',
            'vehicle_compatibility' => 'sometimes|array',
            'vehicle_compatibility.engines' => 'sometimes|array',
            'vehicle_compatibility.engines.*.engine_id' => 'required_with:vehicle_compatibility.engines|integer|exists:vehicle_engines,id',
            'vehicle_compatibility.chassis' => 'sometimes|array',
            'vehicle_compatibility.chassis.*.chassis_id' => 'required_with:vehicle_compatibility.chassis|integer|exists:vehicle_chassis,id',
            'vehicle_compatibility.allEngines' => 'sometimes|boolean',
            'vehicle_compatibility.allChassis' => 'sometimes|boolean',
            'vehicle_compatibility.isUniversal' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'La categoria seleccionada no existe',
            'extra_category_ids.*.exists' => 'Una de las categorias extra seleccionadas no existe',
        ];
    }
}
