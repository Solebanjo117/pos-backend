<?php

namespace App\Http\Requests;

use App\Models\VehicleChassis;
use App\Models\VehicleEngine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCustomerVehicleRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('vin')) {
            $this->merge(['vin' => strtoupper(trim((string) $this->input('vin')))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_model_id' => 'required|integer|exists:vehicle_models,id',
            'vehicle_chassis_id' => 'nullable|integer|exists:vehicle_chassis,id',
            'vehicle_engine_id' => 'required|integer|exists:vehicle_engines,id',
            'vin' => 'required|string|max:40',
            'label' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
            'status' => 'nullable|in:active,inactive',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $modelId = (int) $this->input('vehicle_model_id');
                $chassisId = $this->filled('vehicle_chassis_id') ? (int) $this->input('vehicle_chassis_id') : null;
                $engineId = (int) $this->input('vehicle_engine_id');

                if (! $modelId || ! $engineId) {
                    return;
                }

                if ($chassisId !== null) {
                    $chassisMatches = VehicleChassis::query()
                        ->whereKey($chassisId)
                        ->where(function ($query) use ($modelId): void {
                            $query->where('model_id', $modelId)
                                ->orWhereHas('models', fn ($query) => $query->where('vehicle_models.id', $modelId));
                        })
                        ->exists();

                    if (! $chassisMatches) {
                        $validator->errors()->add('vehicle_chassis_id', 'El chasis no pertenece al modelo seleccionado.');
                    }
                }

                $engineMatches = VehicleEngine::query()
                    ->whereKey($engineId)
                    ->where(function ($query) use ($modelId): void {
                        $query->where('model_id', $modelId)
                            ->orWhereHas('models', fn ($query) => $query->where('vehicle_models.id', $modelId));
                    })
                    ->exists();

                if (! $engineMatches) {
                    $validator->errors()->add('vehicle_engine_id', 'El motor no pertenece al modelo seleccionado.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'vehicle_model_id.required' => 'Selecciona el modelo del carro.',
            'vehicle_engine_id.required' => 'Selecciona el motor del carro.',
            'vin.required' => 'El VIN del carro es obligatorio.',
        ];
    }
}
