<?php

namespace App\Http\Requests;

use App\Models\CustomerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCustomerTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'manager'], true);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'base_kind' => 'required|in:individual,business',
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $existing = CustomerType::query()
                ->where('normalized_name', CustomerType::normalizeName((string) $this->input('name')))
                ->when($this->route('customerType'), fn ($query, $type) => $query->where('id', '!=', $type->id))
                ->exists();
            if ($existing) {
                $validator->errors()->add('name', 'Ya existe un tipo de cliente con ese nombre.');
            }
        }];
    }
}
