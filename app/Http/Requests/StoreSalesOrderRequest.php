<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id'                 => 'nullable|integer|min:1',
            'customer_id'             => 'nullable|exists:customers,id',
            'date'                    => 'nullable|date',
            'status'                  => 'nullable|in:draft,pending,confirmed',
            'discount_amount'         => 'nullable|numeric|min:0',
            'shipping_cost'           => 'nullable|numeric|min:0',
            'payment_method'          => 'nullable|in:cash,card,transfer,credit,multiple',
            'payment_status'          => 'nullable|in:pending,partial,paid,refunded',
            'initial_payment'         => 'nullable|numeric|min:0',
            'notes'                   => 'nullable|string|max:5000',
            'audit_logs'              => 'nullable|array',
            'payments'                => 'nullable|array',
            'payments.*.amount'       => 'required_with:payments|numeric|min:0.01',
            'payments.*.method'       => 'required_with:payments|in:cash,card,transfer,credit,store_credit,visa,mastercard,amex,clave,yappy,transferencia,notaCredito,creditoCliente',
            'payments.*.notes'        => 'nullable|string',
            'items'                   => 'nullable|array',
            'items.*.product_id'      => 'required|exists:products,id',
            'items.*.variant_id'      => 'nullable|exists:product_variants,id',
            'items.*.warehouse_id'    => 'required|exists:warehouses,id',
            'items.*.quantity'        => 'required|integer|min:1',
            'items.*.unit_price'      => 'required|numeric|min:0',
            'items.*.discount_percent'=> 'nullable|numeric|min:0|max:100',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'items.*.tax_percent'     => 'nullable|numeric|min:0|max:100',
            'items.*.seller_id'       => 'nullable|exists:users,id',
        ];
    }
}