<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleChassis;
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
        $this->rejectUnscopedVinEndpoint();
    }

    public function storeByModelEngine(Request $request, int $modelId, int $engineId): JsonResponse
    {
        $this->rejectUnscopedVinEndpoint();
    }

    public function destroyByModelEngine(int $modelId, int $engineId, int $vinId): JsonResponse
    {
        $this->rejectUnscopedVinEndpoint();
    }

    public function indexByModelChassisEngine(int $modelId, int $chassisId, int $engineId): JsonResponse
    {
        $this->resolveScopedVehicle($modelId, $chassisId, $engineId);

        $vins = VehicleVin::query()
            ->where('model_id', $modelId)
            ->where('chassis_id', $chassisId)
            ->where('engine_id', $engineId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $vins]);
    }

    public function storeByModelChassisEngine(Request $request, int $modelId, int $chassisId, int $engineId): JsonResponse
    {
        $this->resolveScopedVehicle($modelId, $chassisId, $engineId);

        $validated = $request->validate([
            'vin' => 'required|string|max:40',
            'notes' => 'nullable|string|max:255',
        ]);

        $vinValue = strtoupper(trim($validated['vin']));
        $vin = VehicleVin::firstOrCreate(
            [
                'model_id' => $modelId,
                'chassis_id' => $chassisId,
                'engine_id' => $engineId,
                'vin' => $vinValue,
            ],
            [
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return response()->json(['data' => $vin], $vin->wasRecentlyCreated ? 201 : 200);
    }

    public function destroyByModelChassisEngine(int $modelId, int $chassisId, int $engineId, int $vinId): JsonResponse
    {
        $this->resolveScopedVehicle($modelId, $chassisId, $engineId);

        $vin = VehicleVin::query()
            ->where('model_id', $modelId)
            ->where('chassis_id', $chassisId)
            ->where('engine_id', $engineId)
            ->findOrFail($vinId);

        $vin->delete();

        return response()->json(['message' => 'VIN eliminado'], 200);
    }

    public function moveScopedVin(Request $request, int $modelId, int $chassisId, int $engineId, int $vinId): JsonResponse
    {
        $this->resolveScopedVehicle($modelId, $chassisId, $engineId);

        $validated = $request->validate([
            'target_chassis_id' => 'required|integer|exists:vehicle_chassis,id',
        ]);

        $targetChassisId = (int) $validated['target_chassis_id'];
        $this->resolveScopedVehicle($modelId, $targetChassisId, $engineId);

        $vin = VehicleVin::query()
            ->where('model_id', $modelId)
            ->where('chassis_id', $chassisId)
            ->where('engine_id', $engineId)
            ->findOrFail($vinId);

        $duplicate = VehicleVin::query()
            ->where('model_id', $modelId)
            ->where('chassis_id', $targetChassisId)
            ->where('engine_id', $engineId)
            ->where('vin', $vin->vin)
            ->first();

        if ($duplicate) {
            $vin->delete();

            return response()->json(['data' => $duplicate], 200);
        }

        $vin->update(['chassis_id' => $targetChassisId]);

        return response()->json(['data' => $vin->refresh()], 200);
    }

    private function rejectUnscopedVinEndpoint(): never
    {
        abort(422, 'Los VINs requieren modelo, chasis y motor.');
    }

    /**
     * @return array{0: VehicleModel, 1: VehicleChassis, 2: VehicleEngine}
     */
    private function resolveScopedVehicle(int $modelId, int $chassisId, int $engineId): array
    {
        [$model, $engine] = $this->resolveModelAndEngine($modelId, $engineId);
        $chassis = VehicleChassis::with('models:id')->whereKey($chassisId)->firstOrFail();

        $chassisBelongsToModel = $chassis->model_id === $model->id
            || $chassis->models->contains('id', $model->id);

        abort_unless($chassisBelongsToModel, 422, 'El chasis no pertenece al modelo seleccionado.');

        return [$model, $chassis, $engine];
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
}
