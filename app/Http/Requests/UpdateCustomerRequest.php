<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends StoreCustomerRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $customer = $this->route('customer');
        $rules['code'] = ['sometimes', 'string', 'max:50', Rule::unique('customers', 'code')->ignore($customer?->id)];
        $rules['customer_type_id'] = 'sometimes|required|integer|exists:customer_types,id';
        $rules['name'] = 'sometimes|required|string|max:255';
        unset($rules['initial_balance']);

        return $rules;
    }
}
