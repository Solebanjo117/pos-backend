<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleChassis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleChassisController extends Controller
{
    /**
     * Display a listing of vehicle chassis.
     */
    public function index(Request $request): JsonResponse
    {
        $query = VehicleChassis::query()->with(['model.make', 'models.make']);

        // Filter by model (legacy model_id or pivot models)
        if ($request->has('model_id')) {
            $modelId = (int) $request->input('model_id');

            $query->where(function ($q) use ($modelId) {
                $q->where('model_id', $modelId)
                    ->orWhereHas('models', function ($modelsQuery) use ($modelId) {
                        $modelsQuery->where('vehicle_models.id', $modelId);
                    });
            });
        }

        // Filter by make (legacy model relation or pivot models)
        if ($request->has('make_id')) {
            $makeId = (int) $request->input('make_id');

            $query->where(function ($q) use ($makeId) {
                $q->whereHas('model', function ($legacyModelQuery) use ($makeId) {
                    $legacyModelQuery->where('make_id', $makeId);
                })->orWhereHas('models', function ($modelsQuery) use ($makeId) {
                    $modelsQuery->where('make_id', $makeId);
                });
            });
        }

        // Search by code or name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('code', 'ilike', "%{$search}%")
                  ->orWhere('name', 'ilike', "%{$search}%");
            });
        }

        // Pagination
        $perPage  = $request->input('per_page', 50);
        $chassis  = $query->orderBy('code')->paginate($perPage);
        $data = collect($chassis->items())
            ->map(fn (VehicleChassis $item) => $this->serializeChassis($item))
            ->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $chassis->currentPage(),
                'last_page'    => $chassis->lastPage(),
                'per_page'     => $chassis->perPage(),
                'total'        => $chassis->total(),
            ],
        ]);
    }

    /**
     * Store a newly created vehicle chassis.
     * model_id is optional — chassis can be independent (mirrors R21 for engines).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'       => 'required|string|max:50',
            'name'       => 'nullable|string|max:255',
            'model_id'   => 'nullable|exists:vehicle_models,id',
            'model_ids'  => 'nullable|array',
            'model_ids.*' => 'integer|exists:vehicle_models,id',
            'year_from'  => 'nullable|string|max:4',
            'year_to'    => 'nullable|string|max:4',
            'notes'      => 'nullable|string',
        ]);

        $modelIds = $this->resolveModelIds($validated, false);
        $validated['model_id'] = $modelIds[0] ?? null;
        unset($validated['model_ids']);

        $chassis = VehicleChassis::create($validated);

        if (!empty($modelIds)) {
            $chassis->models()->sync($modelIds);
        }

        return response()->json([
            'message' => 'Chasis creado exitosamente',
            'data'    => $this->serializeChassis($chassis),
        ], 201);
    }

    /**
     * Display the specified vehicle chassis.
     */
    public function show(string $id): JsonResponse
    {
        $chassis = VehicleChassis::with(['model.make', 'models.make', 'compatibleProducts'])
            ->findOrFail($id);

        return response()->json(['data' => $this->serializeChassis($chassis)]);
    }

    /**
     * Update the specified vehicle chassis.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $chassis = VehicleChassis::findOrFail($id);

        $validated = $request->validate([
            'code'      => 'sometimes|required|string|max:50',
            'name'      => 'nullable|string|max:255',
            'model_id'  => 'nullable|exists:vehicle_models,id',
            'model_ids' => 'nullable|array',
            'model_ids.*' => 'integer|exists:vehicle_models,id',
            'year_from' => 'nullable|string|max:4',
            'year_to'   => 'nullable|string|max:4',
            'notes'     => 'nullable|string',
        ]);

        $modelIds = $this->resolveModelIds($validated, true);
        if ($modelIds !== null) {
            $validated['model_id'] = $modelIds[0] ?? null;
        }
        unset($validated['model_ids']);

        $chassis->update($validated);

        if ($modelIds !== null) {
            $chassis->models()->sync($modelIds);
        }

        return response()->json([
            'message' => 'Chasis actualizado exitosamente',
            'data'    => $this->serializeChassis($chassis),
        ]);
    }

    /**
     * Remove the specified vehicle chassis.
     */
    public function destroy(string $id): JsonResponse
    {
        $chassis = VehicleChassis::findOrFail($id);

        // Check if chassis has products
        if ($chassis->compatibility()->count() > 0) {
            return response()->json([
                'message' => 'No se puede eliminar el chasis porque tiene productos compatibles asociados',
            ], 422);
        }

        $chassis->delete();

        return response()->json([
            'message' => 'Chasis eliminado exitosamente',
        ]);
    }

    /**
     * Get chassis by model ID (helper endpoint for cascading selectors).
     */
    public function byModel(string $modelId): JsonResponse
    {
        $id = (int) $modelId;

        $chassis = VehicleChassis::with(['model.make', 'models.make'])
            ->where(function ($query) use ($id) {
                $query->where('model_id', $id)
                    ->orWhereHas('models', function ($modelsQuery) use ($id) {
                        $modelsQuery->where('vehicle_models.id', $id);
                    });
            })
            ->orderBy('code')
            ->get()
            ->map(fn (VehicleChassis $item) => $this->serializeChassis($item));

        return response()->json(['data' => $chassis]);
    }

    /**
     * Resolve model IDs from legacy/singular and new/plural payload formats.
     *
     * @return array<int>|null
     */
    private function resolveModelIds(array $validated, bool $allowNoChange): ?array
    {
        $hasModelIds = array_key_exists('model_ids', $validated);
        $hasLegacyModelId = array_key_exists('model_id', $validated);

        if (!$hasModelIds && !$hasLegacyModelId) {
            return $allowNoChange ? null : [];
        }

        if ($hasModelIds) {
            $modelIds = collect($validated['model_ids'] ?? [])
                ->filter(fn ($id) => $id !== null && $id !== '')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            if (!empty($modelIds)) {
                return $modelIds;
            }

            if ($hasLegacyModelId && !empty($validated['model_id'])) {
                return [(int) $validated['model_id']];
            }

            return [];
        }

        $legacyModelId = $validated['model_id'] ?? null;

        return !empty($legacyModelId) ? [(int) $legacyModelId] : [];
    }

    private function serializeChassis(VehicleChassis $chassis): VehicleChassis
    {
        $chassis->loadMissing(['model.make', 'models.make']);

        $modelIds = $chassis->models
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (empty($modelIds) && $chassis->model_id !== null) {
            $modelIds = [(int) $chassis->model_id];
        }

        if ($chassis->relationLoaded('model') && $chassis->model === null && $chassis->models->isNotEmpty()) {
            $chassis->setRelation('model', $chassis->models->first());
        }

        $chassis->setAttribute('model_ids', $modelIds);

        if ($chassis->model_id === null && !empty($modelIds)) {
            $chassis->setAttribute('model_id', $modelIds[0]);
        }

        return $chassis;
    }
}
