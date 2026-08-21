<?php

namespace App\Http\Requests;

class UpdateCustomerTypeRequest extends StoreCustomerTypeRequest
{
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:100',
            'base_kind' => 'sometimes|required|in:individual,business',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
