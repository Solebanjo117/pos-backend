<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCashMovementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user?->hasRole(['admin', 'manager', 'cashier'])) {
            return false;
        }

        return ! str_starts_with((string) $this->input('subtype'), 'adjustment_') || $user->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'type' => ['required', 'in:in,out'],
            'subtype' => ['nullable', 'in:cash_in,cash_out,deposit,withdrawal,adjustment_positive,adjustment_negative'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'reason' => ['required', 'string', 'max:255'],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'business_datetime' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $subtype = $this->input('subtype', $this->input('type') === 'in' ? 'cash_in' : 'cash_out');
            $expectedType = in_array($subtype, ['cash_in', 'withdrawal', 'adjustment_positive'], true) ? 'in' : 'out';
            if ($this->input('type') !== $expectedType) {
                $validator->errors()->add('type', 'El tipo no coincide con la operación seleccionada.');
            }
        }];
    }
}
