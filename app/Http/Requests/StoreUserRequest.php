<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:50|unique:users,username',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role' => ['required', Rule::in(['admin', 'manager', 'buyer', 'seller', 'cashier', 'assistant'])],
            'current_warehouse_id' => 'nullable|exists:warehouses,id',
            'is_active' => 'boolean',
            'warehouse_ids' => 'nullable|array',
            'warehouse_ids.*' => 'integer|exists:warehouses,id',
        ];
    }
}
