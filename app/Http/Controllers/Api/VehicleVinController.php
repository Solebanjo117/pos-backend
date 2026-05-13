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
     * List VINs for a given engine.
     */
    public function index(int $engineId): JsonResponse
    {
        $engine = VehicleEngine::with('models:id')->whereKey($engineId)->firstOrFail();
        $modelId = $this->resolveLegacyModelIdFromEngine($engine);

        $vins = VehicleVin::query()
            ->where('model_id', $modelId)
            ->whereNull('chassis_id')
            ->where('engine_id', $engineId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $vins]);
    }

    /**
     * Store a VIN for a given engine.
     */
    public function store(Request $request, int $engineId): JsonResponse
    {
        $engine = VehicleEngine::with('models:id')->findOrFail($engineId);
        $modelId = $this->resolveLegacyModelIdFromEngine($engine);

        $validated = $request->validate([
            'vin' => 'required|string|max:40',
            'notes' => 'nullable|string|max:255',
        ]);

        $vinValue = strtoupper(trim($validated['vin']));
        $vin = VehicleVin::firstOrCreate(
            [
                'model_id' => $modelId,
                'chassis_id' => null,
                'engine_id' => $engineId,
                'vin' => $vinValue,
            ],
            [
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return response()->json(['data' => $vin], $vin->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Delete a VIN by engine.
     */
    public function destroy(int $engineId, int $vinId): JsonResponse
    {
        $engine = VehicleEngine::with('models:id')->whereKey($engineId)->firstOrFail();
        $modelId = $this->resolveLegacyModelIdFromEngine($engine);

        $vin = VehicleVin::query()
            ->where('model_id', $modelId)
            ->whereNull('chassis_id')
            ->where('engine_id', $engineId)
            ->findOrFail($vinId);
        $vin->delete();

        return response()->json(['message' => 'VIN eliminado'], 200);
    }

    /** List VINs for a given model */
    public function indexByModel(int $modelId): JsonResponse
    {
        $vins = VehicleVin::where('model_id', $modelId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $vins]);
    }

    /** Store a VIN for a model */
    public function storeByModel(Request $request, int $modelId): JsonResponse
    {
        VehicleModel::query()->whereKey($modelId)->firstOrFail();

        $validated = $request->validate([
            'vin' => 'required|string|max:40',
            'notes' => 'nullable|string|max:255',
        ]);

        $vinValue = strtoupper(trim($validated['vin']));
        $vin = VehicleVin::firstOrCreate(
            [
                'model_id' => $modelId,
                'chassis_id' => null,
                'engine_id' => null,
                'vin' => $vinValue,
            ],
            [
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return response()->json(['data' => $vin], $vin->wasRecentlyCreated ? 201 : 200);
    }

    /** Delete a VIN by model */
    public function destroyByModel(int $modelId, int $vinId): JsonResponse
    {
        $vin = VehicleVin::where('model_id', $modelId)->findOrFail($vinId);
        $vin->delete();

        return response()->json(['message' => 'VIN eliminado'], 200);
    }

    public function indexByModelEngine(int $modelId, int $engineId): JsonResponse
    {
        $this->resolveModelAndEngine($modelId, $engineId);

        $vins = VehicleVin::query()
            ->where('model_id', $modelId)
            ->whereNull('chassis_id')
            ->where('engine_id', $engineId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $vins]);
    }

    public function storeByModelEngine(Request $request, int $modelId, int $engineId): JsonResponse
    {
        $this->resolveModelAndEngine($modelId, $engineId);

        $validated = $request->validate([
            'vin' => 'required|string|max:40',
            'notes' => 'nullable|string|max:255',
        ]);

        $vinValue = strtoupper(trim($validated['vin']));
        $vin = VehicleVin::firstOrCreate(
            [
                'model_id' => $modelId,
                'chassis_id' => null,
                'engine_id' => $engineId,
                'vin' => $vinValue,
            ],
            [
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return response()->json(['data' => $vin], $vin->wasRecentlyCreated ? 201 : 200);
    }

    public function destroyByModelEngine(int $modelId, int $engineId, int $vinId): JsonResponse
    {
        $this->resolveModelAndEngine($modelId, $engineId);

        $vin = VehicleVin::query()
            ->where('model_id', $modelId)
            ->whereNull('chassis_id')
            ->where('engine_id', $engineId)
            ->findOrFail($vinId);

        $vin->delete();

        return response()->json(['message' => 'VIN eliminado'], 200);
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

    private function resolveModelIdFromEngine(VehicleEngine $engine): ?int
    {
        if ($engine->model_id !== null) {
            return (int) $engine->model_id;
        }

        $engine->loadMissing('models:id');

        $pivotModelId = $engine->models
            ->pluck('id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->first();

        return $pivotModelId !== null ? (int) $pivotModelId : null;
    }

    private function resolveLegacyModelIdFromEngine(VehicleEngine $engine): int
    {
        $engine->loadMissing('models:id');

        $modelIds = $engine->models
            ->pluck('id')
            ->push($engine->model_id)
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        abort_if($modelIds->isEmpty(), 422, 'El motor seleccionado no tiene un modelo asociado.');
        abort_if(
            $modelIds->count() > 1,
            422,
            'El motor pertenece a varios modelos. Usa el endpoint de VIN por modelo y motor.'
        );

        return (int) $modelIds->first();
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
