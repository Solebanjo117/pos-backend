<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSupplierRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => preg_replace('/\s+/u', ' ', trim((string) $this->input('name'))) ?? '']);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'payment_terms' => 'nullable|string|max:255',
            'lead_time_days' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
            'brand_ids' => 'sometimes|array',
            'brand_ids.*' => 'integer|distinct|exists:brands,id',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $normalized = Supplier::normalizeName((string) $this->input('name'));
            $id = (int) ($this->route('id') ?? 0);
            if (Supplier::query()->where('normalized_name', $normalized)->when($id, fn ($query) => $query->where('id', '!=', $id))->exists()) {
                $validator->errors()->add('name', 'Ya existe un proveedor con ese nombre.');
            }
        }];
    }
}
