<?php

namespace App\Http\Requests;

use App\Support\RegionalDateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinancialReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(['admin', 'manager']) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $today = now(RegionalDateRange::timezone());
        $this->mergeIfMissing(['date_from' => $today->copy()->startOfMonth()->toDateString(), 'date_to' => $today->toDateString()]);
    }

    public function rules(): array
    {
        return [
            'field' => ['sometimes', Rule::in(['warehouse_id', 'product_id', 'brand_id', 'category_id', 'customer_id', 'seller_id'])],
            'warehouse_id' => [$this->is('api/v1/reports/*') ? 'required' : 'nullable', 'integer', 'exists:warehouses,id'],
            'date_from' => ['required', 'date_format:Y-m-d'], 'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'as_of' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'mode' => ['nullable', Rule::in(['current', 'as_of', 'period'])],
            'group_by' => ['nullable', Rule::in(['line', 'product', 'brand', 'category', 'customer', 'seller'])],
            'product_id' => ['nullable', 'integer'], 'brand_id' => ['nullable', 'integer'], 'category_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'], 'seller_id' => ['nullable', 'integer'], 'user_id' => ['nullable', 'integer'],
            'pos_session_id' => ['nullable', 'integer'], 'method' => ['nullable', 'string', 'max:100'], 'pending_account' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:120'], 'kind' => ['nullable', Rule::in(['sale', 'return', 'funding', 'expense', 'purchase', 'transfer', 'adjustment'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', Rule::in(['bank', 'petty_cash', 'other'])], 'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort_by' => ['nullable', Rule::in(['name', 'type', 'current_balance', 'created_at'])], 'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    public function messages(): array
    {
        return ['date_to.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.', 'as_of.before_or_equal' => 'El corte no puede ser futuro.'];
    }
}
