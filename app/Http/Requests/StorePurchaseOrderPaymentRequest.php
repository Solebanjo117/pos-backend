<?php

namespace App\Http\Requests;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderPaymentRequest extends FormRequest
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
        $validMethods = array_values(array_unique(array_merge(
            PaymentMethod::realMoneyCodes(PaymentMethod::CONTEXT_PURCHASE_ORDERS),
            ['credit', 'supplier_credit'],
        )));

        return [
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in($validMethods)],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'El monto del pago es obligatorio.',
            'amount.min' => 'El monto del pago debe ser mayor a cero.',
            'warehouse_id.exists' => 'La sucursal seleccionada no es valida.',
            'method.required' => 'El metodo de pago es obligatorio.',
            'method.in' => 'El metodo de pago no es valido.',
        ];
    }
}
