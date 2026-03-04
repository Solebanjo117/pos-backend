<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransferMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['required', 'integer', 'exists:warehouses,id', 'different:warehouse_id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'El producto es obligatorio.',
            'product_id.exists' => 'El producto seleccionado no existe.',
            'warehouse_id.required' => 'El almacén de origen es obligatorio.',
            'warehouse_id.exists' => 'El almacén de origen no existe.',
            'destination_warehouse_id.required' => 'El almacén de destino es obligatorio.',
            'destination_warehouse_id.exists' => 'El almacén de destino no existe.',
            'destination_warehouse_id.different' => 'El almacén de origen y destino deben ser diferentes.',
            'quantity.required' => 'La cantidad es obligatoria.',
            'quantity.min' => 'La cantidad debe ser mayor a 0.',
        ];
    }
}
