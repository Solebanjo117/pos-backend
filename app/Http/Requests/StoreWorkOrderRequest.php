<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // all authenticated roles can create work orders
    }

    public function rules(): array
    {
        return [
            'customer_id'           => 'nullable|exists:customers,id',
            'warehouse_id'          => 'required|exists:warehouses,id',
            'assigned_to'           => 'nullable|exists:users,id',
            'vehicle_description'   => 'nullable|string|max:255',
            'notes'                 => 'nullable|string|max:5000',
            'labour_cost'           => 'nullable|numeric|min:0',
            'estimated_delivery_at' => 'nullable|date',

            'items'                  => 'nullable|array',
            'items.*.product_id'     => 'required|exists:products,id',
            'items.*.variant_id'     => 'nullable|exists:product_variants,id',
            'items.*.warehouse_id'   => 'required|exists:warehouses,id',
            'items.*.quantity'       => 'required|integer|min:1',
            'items.*.unit_price'     => 'required|numeric|min:0',
            'items.*.notes'          => 'nullable|string|max:500',
        ];
    }
}
