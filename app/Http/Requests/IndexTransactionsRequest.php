<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTransactionsRequest extends FormRequest
{
    private const STATUSES = [
        'order:draft', 'order:pending', 'order:confirmed', 'order:processing', 'order:completed', 'order:cancelled',
        'quote:draft', 'quote:sent', 'quote:sent_to_deposit', 'quote:sent_to_cashier', 'quote:accepted',
        'quote:collected', 'quote:rejected', 'quote:expired', 'quote:converted',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(['all', 'order', 'quote'])],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'status_group' => ['nullable', Rule::in(['pending', 'completed', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:255'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', Rule::when($this->filled('date_from'), ['after_or_equal:date_from'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.in' => 'El tipo de transacción no es válido.',
            'status.in' => 'El estado seleccionado no es válido.',
            'status_group.in' => 'El grupo de estados no es válido.',
            'date_to.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
            'per_page.max' => 'El límite es de 100 transacciones por página.',
        ];
    }
}
