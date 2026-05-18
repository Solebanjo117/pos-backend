<?php

namespace App\Http\Requests;

use App\Models\InventoryLot;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $lot = $this->route('lot');

        if (! $user || ! $lot instanceof InventoryLot) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        return $user->warehouses()
            ->whereKey($lot->warehouse_id)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'received_at' => ['required', 'date'],
            'quantity_available' => ['required', 'integer', 'min:0'],
            'unit_cost_base' => ['required', 'numeric', 'min:0'],
            'tax_per_unit' => ['required', 'numeric', 'min:0'],
            'misc_per_unit' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'received_at.required' => 'La fecha de recepcion es obligatoria.',
            'received_at.date' => 'La fecha de recepcion no es valida.',
            'quantity_available.required' => 'El stock disponible es obligatorio.',
            'quantity_available.integer' => 'El stock disponible debe ser un numero entero.',
            'quantity_available.min' => 'El stock disponible no puede ser negativo.',
            'unit_cost_base.required' => 'El costo unitario es obligatorio.',
            'unit_cost_base.numeric' => 'El costo unitario debe ser numerico.',
            'unit_cost_base.min' => 'El costo unitario no puede ser negativo.',
            'tax_per_unit.required' => 'El ITBM unitario es obligatorio.',
            'tax_per_unit.numeric' => 'El ITBM unitario debe ser numerico.',
            'tax_per_unit.min' => 'El ITBM unitario no puede ser negativo.',
            'misc_per_unit.required' => 'El misc unitario es obligatorio.',
            'misc_per_unit.numeric' => 'El misc unitario debe ser numerico.',
            'misc_per_unit.min' => 'El misc unitario no puede ser negativo.',
            'sale_price.required' => 'El precio de venta es obligatorio.',
            'sale_price.numeric' => 'El precio de venta debe ser numerico.',
            'sale_price.min' => 'El precio de venta no puede ser negativo.',
        ];
    }
}
