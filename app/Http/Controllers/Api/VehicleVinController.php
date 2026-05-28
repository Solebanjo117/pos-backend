<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerVehicle;
use App\Models\VehicleEngine;
use App\Models\VehicleModel;
use App\Models\VehicleVin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleVinController extends Controller
{
    /**
     * Block legacy engine-only VIN access.
     */
    public function index(int $engineId): JsonResponse
    {
        $this->rejectUnscopedVinEndpoint();
    }

    /**
     * Block legacy engine-only VIN storage.
     */
    public function store(Request $request, int $engineId): JsonResponse
    {
        $this->rejectUnscopedVinEndpoint();
    }

    /**
     * Block legacy engine-only VIN deletion.
     */
    public function destroy(int $engineId, int $vinId): JsonResponse
    {
        $this->rejectUnscopedVinEndpoint();
    }

    /** Block model-only VIN access. */
    public function indexByModel(int $modelId): JsonResponse
    {
        $this->rejectUnscopedVinEndpoint();
    }

    /** Block model-only VIN storage. */
    public function storeByModel(Request $request, int $modelId): JsonResponse
    {
        $this->rejectUnscopedVinEndpoint();
    }

    /** Block model-only VIN deletion. */
    public function destroyByModel(int $modelId, int $vinId): JsonResponse
    {
        $this->rejectUnscopedVinEndpoint();
    }

    public function indexByModelEngine(int $modelId, int $engineId): JsonResponse
    {
        $this->resolveModelAndEngine($modelId, $engineId);

        return response()->json([
            'data' => $this->modelEngineVins($modelId, $engineId)
                ->get()
                ->map(fn (VehicleVin $vin): array => $this->vehicleVinPayload($vin)),
        ]);
    }

    public function storeByModelEngine(Request $request, int $modelId, int $engineId): JsonResponse
    {
        $this->resolveModelAndEngine($modelId, $engineId);

        return $this->storeModelEngineVin($request, $modelId, $engineId);
    }

    public function destroyByModelEngine(int $modelId, int $engineId, int $vinId): JsonResponse
    {
        $this->resolveModelAndEngine($modelId, $engineId);

        return $this->destroyModelEngineVin($modelId, $engineId, $vinId);
    }

    public function indexByModelChassisEngine(int $modelId, int $chassisId, int $engineId): JsonResponse
    {
        $this->resolveModelAndEngine($modelId, $engineId);

        return response()->json([
            'data' => $this->modelEngineVins($modelId, $engineId)
                ->get()
                ->map(fn (VehicleVin $vin): array => $this->vehicleVinPayload($vin)),
        ]);
    }

    public function storeByModelChassisEngine(Request $request, int $modelId, int $chassisId, int $engineId): JsonResponse
    {
        $this->resolveModelAndEngine($modelId, $engineId);

        return $this->storeModelEngineVin($request, $modelId, $engineId);
    }

    public function destroyByModelChassisEngine(int $modelId, int $chassisId, int $engineId, int $vinId): JsonResponse
    {
        $this->resolveModelAndEngine($modelId, $engineId);

        return $this->destroyModelEngineVin($modelId, $engineId, $vinId);
    }

    public function moveScopedVin(Request $request, int $modelId, int $chassisId, int $engineId, int $vinId): JsonResponse
    {
        abort(422, 'Los VINs ahora se administran por modelo y motor; no se mueven por chasis.');
    }

    private function rejectUnscopedVinEndpoint(): never
    {
        abort(422, 'Los VINs requieren modelo y motor.');
    }

    private function modelEngineVins(int $modelId, int $engineId): \Illuminate\Database\Eloquent\Builder
    {
        return VehicleVin::query()
            ->addSelect([
                'customer_vehicle_count' => CustomerVehicle::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('vehicle_model_id', 'vehicle_vins.model_id')
                    ->whereColumn('vehicle_engine_id', 'vehicle_vins.engine_id')
                    ->whereColumn('vin', 'vehicle_vins.vin')
                    ->where('status', 'active'),
            ])
            ->where('model_id', $modelId)
            ->where('engine_id', $engineId)
            ->orderBy('created_at', 'desc');
    }

    private function storeModelEngineVin(Request $request, int $modelId, int $engineId): JsonResponse
    {
        $validated = $request->validate([
            'vin' => 'required|string|max:40',
            'notes' => 'nullable|string|max:255',
        ]);

        $vinValue = strtoupper(trim($validated['vin']));
        $vin = VehicleVin::firstOrCreate(
            [
                'model_id' => $modelId,
                'engine_id' => $engineId,
                'vin' => $vinValue,
            ],
            [
                'chassis_id' => null,
                'notes' => $validated['notes'] ?? null,
            ]
        );

        $wasRecentlyCreated = $vin->wasRecentlyCreated;

        if ($vin->chassis_id !== null) {
            $vin->update(['chassis_id' => null]);
        }

        return response()->json(
            ['data' => $this->vehicleVinPayload($vin->refresh())],
            $wasRecentlyCreated ? 201 : 200
        );
    }

    private function destroyModelEngineVin(int $modelId, int $engineId, int $vinId): JsonResponse
    {
        $vin = VehicleVin::query()
            ->where('model_id', $modelId)
            ->where('engine_id', $engineId)
            ->findOrFail($vinId);

        abort_if(
            $this->activeCustomerVehicleCount($vin) > 0,
            422,
            'Este VIN está vinculado a un cliente. Elimínalo desde la ficha del cliente.'
        );

        $vin->delete();

        return response()->json(['message' => 'VIN eliminado'], 200);
    }

    /**
     * @return array{0: VehicleModel, 1: VehicleEngine}
     */
    private function resolveModelAndEngine(int $modelId, int $engineId): array
    {
        $model = VehicleModel::query()->whereKey($modelId)->firstOrFail();
        $engine = VehicleEngine::with('models:id')->whereKey($engineId)->firstOrFail();

        $engineBelongsToModel = $engine->model_id === $model->id
            || $engine->models->contains('id', $model->id);

        abort_unless($engineBelongsToModel, 422, 'El motor no pertenece al modelo seleccionado.');

        return [$model, $engine];
    }

    private function activeCustomerVehicleCount(VehicleVin $vin): int
    {
        return $this->activeCustomerVehiclesQuery($vin)
            ->count();
    }

    private function activeCustomerVehiclesQuery(VehicleVin $vin): \Illuminate\Database\Eloquent\Builder
    {
        return CustomerVehicle::query()
            ->where('vehicle_model_id', $vin->model_id)
            ->where('vehicle_engine_id', $vin->engine_id)
            ->where('vin', $vin->vin)
            ->where('status', 'active');
    }

    /**
     * @return array<int, array{id: int, name: string, code: string|null, customer_vehicle_id: int}>
     */
    private function activeCustomerLinks(VehicleVin $vin): array
    {
        return $this->activeCustomerVehiclesQuery($vin)
            ->with('customer:id,code,name')
            ->orderBy('id')
            ->get()
            ->filter(fn (CustomerVehicle $vehicle): bool => $vehicle->customer !== null)
            ->unique('customer_id')
            ->map(fn (CustomerVehicle $vehicle): array => [
                'id' => $vehicle->customer->id,
                'name' => $vehicle->customer->name,
                'code' => $vehicle->customer->code,
                'customer_vehicle_id' => $vehicle->id,
            ])
            ->values()
            ->all();
    }

    private function vehicleVinPayload(VehicleVin $vin): array
    {
        $data = $vin->toArray();
        $customerVehicleCount = (int) (
            $vin->customer_vehicle_count ?? $this->activeCustomerVehicleCount($vin)
        );

        $data['customer_vehicle_count'] = $customerVehicleCount;
        $data['is_customer_linked'] = $customerVehicleCount > 0;
        $data['linked_customers'] = $customerVehicleCount > 0
            ? $this->activeCustomerLinks($vin)
            : [];

        return $data;
    }
}
