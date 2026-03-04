<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignAttributesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attributes' => 'required|array|min:1',
            'attributes.*.attribute_definition_id' => 'required|exists:attribute_definitions,id',
            'attributes.*.numeric_value' => 'nullable|numeric',
            'attributes.*.text_value' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'attributes.required' => 'Debe proporcionar al menos un atributo',
            'attributes.*.attribute_definition_id.exists' => 'La definición de atributo no existe',
        ];
    }
}
