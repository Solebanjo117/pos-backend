<?php

namespace App\Http\Requests;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesReturnRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:500'],
            'refund_method' => ['required', 'string', Rule::in(array_values(array_unique(array_merge(
                PaymentMethod::realMoneyCodes(PaymentMethod::CONTEXT_RETURNS),
                ['balance', 'store_credit', 'receivable'],
            ))))],
            'refund_notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sales_order_item_id' => ['required', 'exists:sales_order_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.user_id' => ['nullable', 'exists:users,id'],
            'items.*.seller_id' => ['nullable', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'refund_method.required' => 'Seleccione el método de reembolso.',
            'refund_method.in' => 'El método de reembolso seleccionado no es válido.',
            'items.required' => 'Seleccione al menos un artículo para devolver.',
            'items.min' => 'Seleccione al menos un artículo para devolver.',
            'items.*.sales_order_item_id.required' => 'Cada línea debe indicar el artículo vendido.',
            'items.*.sales_order_item_id.exists' => 'Uno de los artículos seleccionados no existe.',
            'items.*.quantity.required' => 'Cada línea debe indicar la cantidad a devolver.',
            'items.*.quantity.integer' => 'La cantidad a devolver debe ser un número entero.',
            'items.*.quantity.min' => 'La cantidad a devolver debe ser al menos 1.',
        ];
    }
}
