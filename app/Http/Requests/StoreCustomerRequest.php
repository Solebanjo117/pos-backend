<?php

namespace App\Http\Requests;

use App\Models\CustomerType;
use App\Models\CustomerVehicle;
use App\Models\VehicleChassis;
use App\Models\VehicleEngine;
use App\Models\VehicleModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCustomerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $vehicles = collect($this->input('vehicles', []))->map(function ($vehicle): array {
            $vehicle = is_array($vehicle) ? $vehicle : [];
            foreach (['vin', 'plate_number'] as $field) {
                if (array_key_exists($field, $vehicle)) {
                    $value = strtoupper(trim((string) ($vehicle[$field] ?? '')));
                    $vehicle[$field] = $value === '' ? null : $value;
                }
            }

            return $vehicle;
        })->all();

        if ($this->has('vehicles')) {
            $this->merge(['vehicles' => $vehicles]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'sometimes|string|max:50|unique:customers,code',
            'customer_type_id' => 'required|integer|exists:customer_types,id',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'tax_id' => 'nullable|string|max:100',
            'tax_name' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'credit_limit' => 'nullable|numeric|min:0',
            'credit_days' => 'nullable|integer|min:0',
            'contact_person' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:active,inactive,blocked',
            'initial_balance' => 'nullable|numeric',
            'sync_vehicles' => 'sometimes|boolean',
            'vehicles' => 'sometimes|array',
            'vehicles.*.id' => 'nullable|integer',
            'vehicles.*.vehicle_model_id' => 'required|integer|exists:vehicle_models,id',
            'vehicles.*.vehicle_chassis_id' => 'nullable|integer|exists:vehicle_chassis,id',
            'vehicles.*.vehicle_engine_id' => 'required|integer|exists:vehicle_engines,id',
            'vehicles.*.vehicle_vin_id' => 'nullable|integer|exists:vehicle_vins,id',
            'vehicles.*.vin' => 'nullable|string|max:40',
            'vehicles.*.year' => 'nullable|integer|min:1886|max:'.(now()->year + 1),
            'vehicles.*.plate_number' => 'nullable|string|max:30',
            'vehicles.*.notes' => 'nullable|string|max:5000',
            'vehicles.*.status' => 'nullable|in:active,inactive',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $type = CustomerType::query()->find($this->integer('customer_type_id'));
            $currentCustomer = $this->route('customer');
            $keepsCurrentType = $currentCustomer
                && (int) $currentCustomer->customer_type_id === (int) $type?->id;
            if ($type && ! $type->is_active && ! $keepsCurrentType) {
                $validator->errors()->add('customer_type_id', 'El tipo de cliente está inactivo.');
            }

            $seenVins = [];
            $seenCatalogVins = [];
            foreach ($this->input('vehicles', []) as $index => $vehicle) {
                $model = VehicleModel::query()->find($vehicle['vehicle_model_id'] ?? null);
                $engineId = (int) ($vehicle['vehicle_engine_id'] ?? 0);
                if (! $model || ! $engineId) {
                    continue;
                }

                $engineMatches = VehicleEngine::query()->whereKey($engineId)
                    ->where(function ($query) use ($model): void {
                        $query->where('model_id', $model->id)
                            ->orWhereHas('models', fn ($query) => $query->where('vehicle_models.id', $model->id));
                    })->exists();
                if (! $engineMatches) {
                    $validator->errors()->add("vehicles.{$index}.vehicle_engine_id", 'El motor no pertenece al modelo seleccionado.');
                }

                $chassisId = $vehicle['vehicle_chassis_id'] ?? null;
                if ($chassisId && ! VehicleChassis::query()->whereKey($chassisId)
                    ->where(function ($query) use ($model): void {
                        $query->where('model_id', $model->id)
                            ->orWhereHas('models', fn ($query) => $query->where('vehicle_models.id', $model->id));
                    })->exists()) {
                    $validator->errors()->add("vehicles.{$index}.vehicle_chassis_id", 'El chasis no pertenece al modelo seleccionado.');
                }

                $year = isset($vehicle['year']) ? (int) $vehicle['year'] : null;
                if ($year && (($model->year_from && $year < $model->year_from) || ($model->year_to && $year > $model->year_to))) {
                    $validator->errors()->add("vehicles.{$index}.year", "El año debe estar dentro del rango {$model->year_from}-{$model->year_to}.");
                }

                $vehicleId = (int) ($vehicle['id'] ?? 0);
                if ($vehicleId && $currentCustomer && ! $currentCustomer->vehicles()->whereKey($vehicleId)->exists()) {
                    $validator->errors()->add("vehicles.{$index}.id", 'El vehículo no pertenece al cliente editado.');
                }
                $catalogVinId = (int) ($vehicle['vehicle_vin_id'] ?? 0);
                if ($catalogVinId) {
                    if (isset($seenCatalogVins[$catalogVinId])) {
                        $validator->errors()->add("vehicles.{$index}.vehicle_vin_id", 'El VIN está repetido en el formulario.');
                    }
                    $seenCatalogVins[$catalogVinId] = true;
                    $catalogMatches = \App\Models\VehicleVin::query()
                        ->whereKey($catalogVinId)
                        ->where('model_id', $model->id)
                        ->where('engine_id', $engineId)
                        ->exists();
                    if (! $catalogMatches) {
                        $validator->errors()->add("vehicles.{$index}.vehicle_vin_id", 'El VIN no pertenece a la configuración seleccionada.');
                    }
                }

                $vin = strtoupper(trim((string) ($vehicle['vin'] ?? '')));
                if ($vin !== '') {
                    if (isset($seenVins[$vin])) {
                        $validator->errors()->add("vehicles.{$index}.vin", 'El VIN está repetido en el formulario.');
                    }
                    $seenVins[$vin] = true;

                    $alreadyAssigned = CustomerVehicle::query()
                        ->where('status', 'active')
                        ->whereRaw('UPPER(vin) = ?', [$vin])
                        ->when($vehicleId, fn ($query) => $query->where('id', '!=', $vehicleId))
                        ->exists();
                    if ($alreadyAssigned) {
                        $validator->errors()->add("vehicles.{$index}.vin", 'El VIN ya está asociado a otro vehículo de cliente.');
                    }
                }
            }
        }];
    }
}
