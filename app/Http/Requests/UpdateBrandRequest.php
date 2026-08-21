<?php

namespace App\Http\Requests;

class UpdateBrandRequest extends StoreBrandRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = 'sometimes|required|string|max:255';

        return $rules;
    }
}
