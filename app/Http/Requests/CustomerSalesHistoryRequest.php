<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerSalesHistoryRequest extends FormRequest
{
    private const SALES_STATUSES = ['draft', 'pending', 'confirmed', 'processing', 'completed', 'cancelled'];

    private const PAYMENT_STATUSES = ['pending', 'partial', 'paid', 'refunded'];

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
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'status' => ['nullable', 'string', Rule::in(self::SALES_STATUSES)],
            'payment_status' => ['nullable', 'string', 'max:100', $this->validPaymentStatuses()],
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_from.date' => 'La fecha inicial no es valida.',
            'date_to.date' => 'La fecha final no es valida.',
            'date_to.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
            'status.in' => 'El estado de venta seleccionado no es valido.',
            'per_page.integer' => 'La cantidad por pagina debe ser un numero entero.',
            'per_page.min' => 'La cantidad por pagina debe ser al menos 1.',
            'per_page.max' => 'La cantidad por pagina no puede exceder 100.',
        ];
    }
}
