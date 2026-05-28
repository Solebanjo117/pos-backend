<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('warehouse_id')) {
            return;
        }

        $warehouseId = $this->user()?->getEffectiveWarehouseId();

        if (! $warehouseId) {
            return;
        }

        $this->merge([
            'warehouse_id' => $warehouseId,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'warehouse_id.required' => 'Debe seleccionar una sucursal para consultar el perfil operativo del producto.',
            'warehouse_id.exists' => 'El almacen seleccionado no existe.',
            'variant_id.exists' => 'La variante seleccionada no existe.',
            'per_page.max' => 'No se pueden solicitar mas de 50 registros por seccion.',
            'date_to.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
        ];
    }
}
