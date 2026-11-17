<?php

namespace App\Http\Requests;

use App\Models\InventoryLot;
use Illuminate\Foundation\Http\FormRequest;

class AssignInventoryLotSupplierRequest extends FormRequest
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

        return $user->warehouses()->whereKey($lot->warehouse_id)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['present', 'nullable', 'integer', 'exists:suppliers,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier_id.present' => 'Indica el proveedor del lote.',
            'supplier_id.exists' => 'El proveedor no existe.',
        ];
    }
}
