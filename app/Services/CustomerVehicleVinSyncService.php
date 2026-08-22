<?php

namespace App\Services;

use App\Models\CustomerVehicle;
use App\Models\VehicleVin;

class CustomerVehicleVinSyncService
{
    public function sync(CustomerVehicle $vehicle): void
    {
        if ($vehicle->status !== 'active') {
            return;
        }

        if ($vehicle->vehicle_vin_id) {
            $catalogVin = VehicleVin::query()->findOrFail($vehicle->vehicle_vin_id);
            if (blank($vehicle->vin)) {
                $vehicle->forceFill(['vehicle_vin_id' => null])->saveQuietly();

                return;
            }
            if ((int) $vehicle->vehicle_model_id === (int) $catalogVin->model_id
                && (int) $vehicle->vehicle_engine_id === (int) $catalogVin->engine_id
                && $vehicle->vin === $catalogVin->vin) {
                return;
            }
            $vehicle->forceFill(['vehicle_vin_id' => null])->saveQuietly();
        }

        if (blank($vehicle->vin)) {
            return;
        }

        $vehicle->loadMissing('customer');

        $catalogVin = VehicleVin::query()->updateOrCreate(
            [
                'model_id' => $vehicle->vehicle_model_id,
                'engine_id' => $vehicle->vehicle_engine_id,
                'vin' => $vehicle->vin,
            ],
            [
                'chassis_id' => null,
                'notes' => $vehicle->customer?->name,
            ]
        );

        if ((int) $vehicle->vehicle_vin_id !== (int) $catalogVin->id) {
            $vehicle->forceFill(['vehicle_vin_id' => $catalogVin->id])->saveQuietly();
        }
    }

    /**
     * @param  array{vehicle_model_id:int, vehicle_engine_id:int, vin:?string}  $scope
     */
    public function cleanupScope(array $scope): void
    {
        if (blank($scope['vin'])) {
            return;
        }

        // Customer associations never own the master VIN lifecycle. Removing a
        // customer vehicle must not delete a VIN from the shared catalog.
    }

    /**
     * @return array{vehicle_model_id:int, vehicle_engine_id:int, vin:?string}
     */
    public function scopeFromVehicle(CustomerVehicle $vehicle): array
    {
        return [
            'vehicle_model_id' => (int) $vehicle->vehicle_model_id,
            'vehicle_engine_id' => (int) $vehicle->vehicle_engine_id,
            'vin' => $vehicle->vin,
        ];
    }

    /**
     * @param  array{vehicle_model_id:int, vehicle_engine_id:int, vin:?string}  $first
     * @param  array{vehicle_model_id:int, vehicle_engine_id:int, vin:?string}  $second
     */
    public function sameScope(array $first, array $second): bool
    {
        return $first['vehicle_model_id'] === $second['vehicle_model_id']
            && $first['vehicle_engine_id'] === $second['vehicle_engine_id']
            && $first['vin'] === $second['vin'];
    }
}
