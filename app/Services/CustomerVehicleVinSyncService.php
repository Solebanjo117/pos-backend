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

        $vehicle->loadMissing('customer');

        VehicleVin::query()->updateOrCreate(
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
    }

    /**
     * @param  array{vehicle_model_id:int, vehicle_engine_id:int, vin:string}  $scope
     */
    public function cleanupScope(array $scope): void
    {
        $replacement = CustomerVehicle::query()
            ->with('customer')
            ->where('vehicle_model_id', $scope['vehicle_model_id'])
            ->where('vehicle_engine_id', $scope['vehicle_engine_id'])
            ->where('vin', $scope['vin'])
            ->where('status', 'active')
            ->latest()
            ->first();

        if ($replacement) {
            $this->sync($replacement);

            return;
        }

        VehicleVin::query()
            ->where('model_id', $scope['vehicle_model_id'])
            ->where('engine_id', $scope['vehicle_engine_id'])
            ->where('vin', $scope['vin'])
            ->delete();
    }

    /**
     * @return array{vehicle_model_id:int, vehicle_engine_id:int, vin:string}
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
     * @param  array{vehicle_model_id:int, vehicle_engine_id:int, vin:string}  $first
     * @param  array{vehicle_model_id:int, vehicle_engine_id:int, vin:string}  $second
     */
    public function sameScope(array $first, array $second): bool
    {
        return $first['vehicle_model_id'] === $second['vehicle_model_id']
            && $first['vehicle_engine_id'] === $second['vehicle_engine_id']
            && $first['vin'] === $second['vin'];
    }
}
