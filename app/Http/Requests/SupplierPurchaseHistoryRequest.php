<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierPurchaseHistoryRequest extends FormRequest
{
    private const PURCHASE_STATUSES = ['draft', 'open', 'sent', 'partial', 'received', 'cancelled'];

    private const PAYMENT_STATUSES = ['pending', 'partial', 'paid'];

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
            'search' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', 'string', Rule::in(self::PURCHASE_STATUSES)],
            'payment_status' => ['nullable', 'string', Rule::in(self::PAYMENT_STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_from.date' => 'La fecha inicial no es valida.',
            'date_to.date' => 'La fecha final no es valida.',
            'date_to.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
            'status.in' => 'El estado de orden seleccionado no es valido.',
            'payment_status.in' => 'El estado de pago seleccionado no es valido.',
            'per_page.max' => 'La cantidad por pagina no puede exceder 100.',
        ];
    }
}
