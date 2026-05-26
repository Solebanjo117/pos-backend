<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerVehicleRequest;
use App\Http\Requests\UpdateCustomerVehicleRequest;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Services\CustomerVehicleVinSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerVehicleController extends Controller
{
    public function __construct(private CustomerVehicleVinSyncService $vinSyncService) {}

    public function index(Customer $customer): JsonResponse
    {
        $vehicles = $customer->vehicles()
            ->with(['model.make', 'chassis', 'engine'])
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->latest()
            ->get();

        return response()->json(['data' => $vehicles]);
    }

    public function store(StoreCustomerVehicleRequest $request, Customer $customer): JsonResponse
    {
        $vehicle = DB::transaction(function () use ($request, $customer): CustomerVehicle {
            $data = $request->validated();
            $data['status'] = $data['status'] ?? 'active';

            $duplicateExists = $customer->vehicles()
                ->where('vin', $data['vin'])
                ->exists();

            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'vin' => 'Este cliente ya tiene un carro con ese VIN.',
                ]);
            }

            $vehicle = $customer->vehicles()->create($data);
            $this->vinSyncService->sync($vehicle);

            return $vehicle;
        });

        return response()->json([
            'data' => $vehicle->load(['model.make', 'chassis', 'engine']),
        ], 201);
    }

    public function update(UpdateCustomerVehicleRequest $request, Customer $customer, CustomerVehicle $vehicle): JsonResponse
    {
        $this->ensureVehicleBelongsToCustomer($customer, $vehicle);

        $vehicle = DB::transaction(function () use ($request, $customer, $vehicle): CustomerVehicle {
            $data = $request->validated();

            if (isset($data['vin'])) {
                $duplicateExists = $customer->vehicles()
                    ->where('vin', $data['vin'])
                    ->whereKeyNot($vehicle->id)
                    ->exists();

                if ($duplicateExists) {
                    throw ValidationException::withMessages([
                        'vin' => 'Este cliente ya tiene un carro con ese VIN.',
                    ]);
                }
            }

            $previousScope = $this->vinSyncService->scopeFromVehicle($vehicle);
            $vehicle->update($data);
            $nextScope = $this->vinSyncService->scopeFromVehicle($vehicle);

            if (! $this->vinSyncService->sameScope($previousScope, $nextScope) || $vehicle->status !== 'active') {
                $this->vinSyncService->cleanupScope($previousScope);
            }

            $this->vinSyncService->sync($vehicle);

            return $vehicle->fresh();
        });

        return response()->json([
            'data' => $vehicle->load(['model.make', 'chassis', 'engine']),
        ]);
    }

    public function destroy(Customer $customer, CustomerVehicle $vehicle): JsonResponse
    {
        $this->ensureVehicleBelongsToCustomer($customer, $vehicle);

        $vehicle = DB::transaction(function () use ($vehicle): CustomerVehicle {
            $previousScope = $this->vinSyncService->scopeFromVehicle($vehicle);
            $vehicle->update(['status' => 'inactive']);
            $this->vinSyncService->cleanupScope($previousScope);

            return $vehicle->fresh();
        });

        return response()->json([
            'message' => 'Carro del cliente desactivado',
            'data' => $vehicle->load(['model.make', 'chassis', 'engine']),
        ]);
    }

    private function ensureVehicleBelongsToCustomer(Customer $customer, CustomerVehicle $vehicle): void
    {
        if ((int) $vehicle->customer_id !== (int) $customer->id) {
            abort(404);
        }
    }
}
