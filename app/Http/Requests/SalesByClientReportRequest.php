<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalesByClientReportRequest extends FormRequest
{
    private const SALES_STATUSES = ['draft', 'pending', 'confirmed', 'processing', 'completed'];

    private const PAYMENT_STATUSES = ['pending', 'partial', 'paid', 'refunded'];

    private const SORT_FIELDS = [
        'customer_name',
        'orders_count',
        'total_revenue',
        'total_paid',
        'pending_amount',
        'last_payment_date',
        'status',
    ];

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
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'referred_by_customer_id' => 'nullable|integer|exists:customers,id',
            'search' => 'nullable|string|max:255',
            'status' => ['nullable', 'string', Rule::in(self::SALES_STATUSES)],
            'payment_status' => ['nullable', 'string', 'max:100', $this->validPaymentStatuses()],
            'sort_by' => ['nullable', 'string', Rule::in(self::SORT_FIELDS)],
            'sort_direction' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_from.required' => 'La fecha inicial es obligatoria.',
            'date_from.date' => 'La fecha inicial no es valida.',
            'date_to.required' => 'La fecha final es obligatoria.',
            'date_to.date' => 'La fecha final no es valida.',
            'date_to.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
            'warehouse_id.exists' => 'La sucursal seleccionada no existe.',
            'customer_id.exists' => 'El cliente seleccionado no existe.',
            'referred_by_customer_id.exists' => 'El referido seleccionado no existe.',
            'status.in' => 'El estado de venta seleccionado no es valido.',
            'sort_by.in' => 'La columna de ordenamiento no es valida.',
            'sort_direction.in' => 'La direccion de ordenamiento no es valida.',
            'per_page.max' => 'La cantidad por pagina no puede exceder 100.',
        ];
    }

    private function validPaymentStatuses(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $statuses = collect(explode(',', (string) $value))
                ->map(fn (string $status): string => trim($status))
                ->filter()
                ->values();

            if ($statuses->isEmpty()) {
                return;
            }

            $invalidStatuses = $statuses
                ->reject(fn (string $status): bool => in_array($status, self::PAYMENT_STATUSES, true));

            if ($invalidStatuses->isNotEmpty()) {
                $fail('El estado de pago seleccionado no es valido.');
            }
        };
    }
}
