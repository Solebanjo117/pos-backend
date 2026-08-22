<?php

namespace App\Http\Requests;

class UpdateSupplierRequest extends StoreSupplierRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = 'sometimes|required|string|max:255';

        return $rules;
    }
}
