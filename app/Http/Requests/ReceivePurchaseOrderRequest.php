<?php

namespace App\Http\Requests;

use App\Models\PurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceivePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(
            PurchaseOrder::query()->whereKey((int) $this->route('id'))->exists(),
            404,
            'La orden de compra no existe.'
        );

        return true;
    }

    public function rules(): array
    {
        $purchaseOrderId = (int) $this->route('id');

        return [
            'idempotency_key' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('purchase_order_items', 'id')
                    ->where('purchase_order_id', $purchaseOrderId),
            ],
            'items.*.quantity_received' => ['required', 'integer', 'min:1'],
            'items.*.batch_number' => ['nullable', 'string', 'max:100'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.sale_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'La clave de idempotencia es obligatoria.',
            'idempotency_key.uuid' => 'La clave de idempotencia no es válida.',
            'items.*.item_id.distinct' => 'Una línea de compra no puede recibirse dos veces en la misma solicitud.',
            'items.*.item_id.exists' => 'La línea seleccionada no pertenece a esta orden de compra.',
        ];
    }
}
