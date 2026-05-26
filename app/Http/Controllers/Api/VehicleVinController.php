<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        return response()->json(['data' => $this->modelEngineVins($modelId, $engineId)->get()]);
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

        return response()->json(['data' => $this->modelEngineVins($modelId, $engineId)->get()]);
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

        return response()->json(['data' => $vin->refresh()], $wasRecentlyCreated ? 201 : 200);
    }

    private function destroyModelEngineVin(int $modelId, int $engineId, int $vinId): JsonResponse
    {
        $vin = VehicleVin::query()
            ->where('model_id', $modelId)
            ->where('engine_id', $engineId)
            ->findOrFail($vinId);

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
}
