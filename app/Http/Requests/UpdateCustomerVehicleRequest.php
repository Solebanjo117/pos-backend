<?php

namespace App\Http\Requests;

class UpdateCustomerVehicleRequest extends StoreCustomerVehicleRequest
{
    public function rules(): array
    {
        return [
            'vehicle_model_id' => 'sometimes|required|integer|exists:vehicle_models,id',
            'vehicle_chassis_id' => 'sometimes|nullable|integer|exists:vehicle_chassis,id',
            'vehicle_engine_id' => 'sometimes|required|integer|exists:vehicle_engines,id',
            'vin' => 'sometimes|nullable|string|max:40',
            'label' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
            'status' => 'nullable|in:active,inactive',
        ];
    }
}
