<?php

namespace App\Http\Requests;

use App\Helpers\TaxRateHelper;
use App\Models\CustomerVehicle;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveSalesQuoteDraftRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $items = $this->input('items');
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $tax = $item['tax_percent'] ?? null;
            if ($tax === null || $tax === '') {
                $items[$index]['tax_percent'] = TaxRateHelper::defaultRate();
            } elseif (is_numeric($tax)) {
                $items[$index]['tax_percent'] = (int) round((float) $tax);
            }
        }

        $this->merge(['items' => $items]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quote_id' => 'nullable|integer|min:1',
            'expected_revision' => 'required|integer|min:0',
            'customer_id' => 'present|nullable|exists:customers,id',
            'seller_id' => 'nullable|integer|exists:users,id',
            'customer_vehicle_id' => 'present|nullable|exists:customer_vehicles,id',
            'vehicle_context' => 'nullable|array:make_id,make_name,model_id,model_name,engine_id,engine_name,year',
            'vehicle_context.make_id' => 'nullable|integer|exists:vehicle_makes,id',
            'vehicle_context.make_name' => 'nullable|string|max:255',
            'vehicle_context.model_id' => 'nullable|integer|exists:vehicle_models,id',
            'vehicle_context.model_name' => 'nullable|string|max:255',
            'vehicle_context.engine_id' => 'nullable|integer|exists:vehicle_engines,id',
            'vehicle_context.engine_name' => 'nullable|string|max:255',
            'vehicle_context.year' => 'nullable|integer|between:1900,2200',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'notes' => 'present|nullable|string|max:5000',
            'internal_notes' => 'present|nullable|string|max:5000',
            'items' => 'required|array|min:1',
            'items.*' => 'required|array',
            'items.*.product_id' => 'nullable|integer|exists:products,id',
            'items.*.variant_id' => 'nullable|integer|exists:product_variants,id',
            'items.*.warehouse_id' => 'required|integer|exists:warehouses,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'items.*.tax_percent' => 'required|integer|min:0|max:100',
            'items.*.notes' => 'nullable|string|max:5000',
            'items.*.is_custom_line' => 'nullable|boolean',
            'items.*.custom_description' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'items.min' => 'Agrega al menos un producto antes de guardar la cotización.',
            'items.*.quantity.min' => 'La cantidad debe ser mayor que cero.',
            'items.*.warehouse_id.required' => 'Cada producto debe tener una sucursal.',
            'expected_revision.required' => 'Falta la versión del borrador. Recarga la cotización antes de guardar.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $sellerId = $this->input('seller_id');
            $preservesSeller = $this->filled('quote_id') && \App\Models\SalesQuote::query()
                ->whereKey($this->input('quote_id'))
                ->where('warehouse_id', $this->input('warehouse_id'))
                ->where('user_id', $sellerId)->exists();
            if ($sellerId && ! $preservesSeller && (int) $sellerId !== (int) $this->user()->id
                && ! in_array($this->user()->role, ['admin', 'manager'], true)) {
                $validator->errors()->add('seller_id', 'No puedes asignar esta cotización a otro vendedor.');
            }
            if ($sellerId) {
                $seller = User::query()->find($sellerId);
                if ($seller && (! $seller->is_active || ! $seller->hasWarehouseAccess((int) $this->input('warehouse_id')))) {
                    $validator->errors()->add('seller_id', 'El vendedor no está activo en esta sucursal.');
                }
            }
            $context = $this->input('vehicle_context');
            if (is_array($context) && ! empty($context['model_id'])) {
                $model = VehicleModel::query()->find($context['model_id']);
                if (! $model || (int) $model->make_id !== (int) ($context['make_id'] ?? 0)) {
                    $validator->errors()->add('vehicle_context.model_id', 'El modelo no pertenece a la marca seleccionada.');
                }
                if ($model && ! empty($context['year'])
                    && ((int) $context['year'] < (int) $model->year_from
                        || ($model->year_to !== null && (int) $context['year'] > (int) $model->year_to))) {
                    $validator->errors()->add('vehicle_context.year', 'El año no corresponde al modelo seleccionado.');
                }
            }
            if (is_array($context) && ! empty($context['engine_id'])) {
                $engine = VehicleEngine::query()->find($context['engine_id']);
                $modelId = (int) ($context['model_id'] ?? 0);
                if (! $engine || ! $modelId || ($engine->model_id !== $modelId && ! $engine->models()->whereKey($modelId)->exists())) {
                    $validator->errors()->add('vehicle_context.engine_id', 'El motor no pertenece al modelo seleccionado.');
                }
            }
            $customerId = $this->input('customer_id');
            $vehicleId = $this->input('customer_vehicle_id');
            if ($vehicleId && (! $customerId || ! CustomerVehicle::query()
                ->whereKey($vehicleId)
                ->where('customer_id', $customerId)
                ->where('status', 'active')
                ->exists())) {
                $validator->errors()->add('customer_vehicle_id', 'El vehículo seleccionado no pertenece al cliente o está inactivo.');
            }

            foreach ($this->input('items', []) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                if ((int) ($item['warehouse_id'] ?? 0) !== (int) $this->input('warehouse_id')) {
                    $validator->errors()->add("items.{$index}.warehouse_id", 'El producto debe pertenecer a la sucursal de la cotización.');
                }

                if (! empty($item['is_custom_line'])) {
                    if (trim((string) ($item['custom_description'] ?? '')) === '') {
                        $validator->errors()->add("items.{$index}.custom_description", 'La descripción es obligatoria para artículos misceláneos.');
                    }
                } elseif (empty($item['product_id'])) {
                    $validator->errors()->add("items.{$index}.product_id", 'El producto es obligatorio.');
                } else {
                    $product = Product::query()->find($item['product_id']);
                    if ($product && ! $product->isVendible()) {
                        $validator->errors()->add("items.{$index}.product_id", 'El producto no está disponible para la venta.');
                    }
                    if (! empty($item['variant_id']) && ! ProductVariant::query()
                        ->whereKey($item['variant_id'])
                        ->where('product_id', $item['product_id'])
                        ->exists()) {
                        $validator->errors()->add("items.{$index}.variant_id", 'La variante no pertenece al producto.');
                    }
                }

                if (is_numeric($item['discount_amount'] ?? null)
                    && is_numeric($item['quantity'] ?? null)
                    && is_numeric($item['unit_price'] ?? null)
                    && (float) $item['discount_amount'] > round((float) $item['quantity'] * (float) $item['unit_price'], 2)) {
                    $validator->errors()->add("items.{$index}.discount_amount", 'El descuento no puede superar el importe bruto de la línea.');
                }
            }
        });
    }
}
