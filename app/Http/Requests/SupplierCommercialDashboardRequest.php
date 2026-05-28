<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SupplierCommercialDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('only_low_stock')) {
            return;
        }

        $onlyLowStock = $this->input('only_low_stock');

        if (! is_string($onlyLowStock)) {
            return;
        }

        $this->merge([
            'only_low_stock' => match (strtolower($onlyLowStock)) {
                'true' => true,
                'false' => false,
                default => $onlyLowStock,
            },
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'only_low_stock' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'warehouse_id.exists' => 'La sucursal seleccionada no es valida.',
            'only_low_stock.boolean' => 'El filtro de bajo stock debe ser verdadero o falso.',
            'per_page.max' => 'La cantidad por pagina no puede exceder 100.',
        ];
    }
}
