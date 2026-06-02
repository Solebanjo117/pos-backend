<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(['pending', 'in_progress', 'done', 'delivered', 'cancelled'])],
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'customer_vehicle_id' => 'nullable|integer|exists:customer_vehicles,id',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'search' => 'nullable|string|max:255',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }
}
