<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReconcileWarehouseCashRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return ['amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'], 'cutoff_at' => ['required', 'date', 'before_or_equal:now'], 'reason' => ['required', 'string', 'max:1000']];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Indique el motivo y la referencia de la conciliación.', 'cutoff_at.before_or_equal' => 'La fecha de corte no puede ser futura.'];
    }
}
