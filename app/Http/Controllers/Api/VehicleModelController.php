<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleChassis;
use App\Models\VehicleModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleModelController extends Controller
{
    /**
     * Display a listing of vehicle models.
     */
    public function index(Request $request): JsonResponse
    {
        $query = VehicleModel::query()
            ->with(['make', 'engines', 'chassis'])
            ->withCount('engines');

        // Filter by make
        if ($request->has('make_id')) {
            $query->where('make_id', $request->input('make_id'));
        }

        // Search
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhereHas('make', function ($makeQuery) use ($search) {
                        $makeQuery->where('name', 'ilike', "%{$search}%");
                    });
            });
        }

        // Filter by year
        if ($request->has('year')) {
            $year = $request->input('year');
            $query->where('year_from', '<=', $year)
                ->where('year_to', '>=', $year);
        }

        // Pagination
        $perPage = $request->input('per_page', 20);
        $models = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => $models->items(),
            'meta' => [
                'current_page' => $models->currentPage(),
                'last_page' => $models->lastPage(),
                'per_page' => $models->perPage(),
                'total' => $models->total(),
            ],
        ]);
    }

    /**
     * Store a newly created vehicle model.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'make_id' => 'required|exists:vehicle_makes,id',
            'name' => 'required|string|max:100',
            'year_from' => 'required|integer|min:1900|max:'.(date('Y') + 2),
            'year_to' => 'required|integer|min:1900|max:'.(date('Y') + 2).'|gte:year_from',
            'generation' => 'nullable|string|max:20',
            'chassis_id' => 'nullable|integer|exists:vehicle_chassis,id',
        ]);

        $validated['generation'] = $this->normalizeGenerationCode($validated['generation'] ?? null);

        $chassisId = $validated['chassis_id'] ?? null;
        unset($validated['chassis_id']);

        $this->validateChassisConsistency($validated['generation'] ?? null, $chassisId, $validated);

        $model = VehicleModel::create($validated);
        $this->syncModelChassis($model, $validated['generation'] ?? null, $chassisId);

        return response()->json([
            'message' => 'Modelo creado exitosamente',
            'data' => $model->load(['make', 'engines', 'chassis']),
        ], 201);
    }

    /**
     * Display the specified vehicle model.
     */
    public function show(string $id): JsonResponse
    {
        $model = VehicleModel::with(['make', 'engines', 'chassis'])->findOrFail($id);

        return response()->json(['data' => $model]);
    }

    /**
     * Update the specified vehicle model.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $model = VehicleModel::findOrFail($id);

        $validated = $request->validate([
            'make_id' => 'sometimes|required|exists:vehicle_makes,id',
            'name' => 'sometimes|required|string|max:100',
            'year_from' => 'sometimes|required|integer|min:1900|max:'.(date('Y') + 2),
            'year_to' => 'sometimes|required|integer|min:1900|max:'.(date('Y') + 2).'|gte:year_from',
            'generation' => 'nullable|string|max:20',
            'chassis_id' => 'nullable|integer|exists:vehicle_chassis,id',
        ]);

        if (array_key_exists('generation', $validated)) {
            $validated['generation'] = $this->normalizeGenerationCode($validated['generation']);
        }

        $chassisId = $validated['chassis_id'] ?? null;
        unset($validated['chassis_id']);

        $generation = $validated['generation'] ?? $model->generation;
        $mergedForValidation = array_merge($model->toArray(), $validated);
        $this->validateChassisConsistency($generation, $chassisId, $mergedForValidation);

        $model->update($validated);

        if (array_key_exists('generation', $validated) || $chassisId !== null) {
            $this->syncModelChassis($model, $model->generation, $chassisId);
        } elseif ($model->generation && (array_key_exists('year_from', $validated) || array_key_exists('year_to', $validated))) {
            // Years changed on a model that already has a generation — sync chassis years too
            $this->syncModelChassis($model, $model->generation, null);
        }

        return response()->json([
            'message' => 'Modelo actualizado exitosamente',
            'data' => $model->load(['make', 'engines', 'chassis']),
        ]);
    }

    /**
     * Remove the specified vehicle model.
     */
    public function destroy(string $id): JsonResponse
    {
        $model = VehicleModel::findOrFail($id);

        // Check if model has engines
        if ($model->engines()->count() > 0) {
            return response()->json([
                'message' => 'No se puede eliminar el modelo porque tiene motores asociados',
            ], 422);
        }

        $model->delete();

        return response()->json([
            'message' => 'Modelo eliminado exitosamente',
        ]);
    }

    /**
     * Get models by make ID (helper endpoint).
     */
    public function byMake(string $makeId): JsonResponse
    {
        $models = VehicleModel::where('make_id', $makeId)
            ->with(['engines', 'chassis'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $models]);
    }

    private function normalizeGenerationCode(?string $generation): ?string
    {
        if ($generation === null) {
            return null;
        }

        $normalized = strtoupper(trim($generation));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Validate that chassis_id and generation are consistent, and years don't contradict an existing chassis.
     */
    private function validateChassisConsistency(?string $generation, ?int $chassisId, array $modelData): void
    {
        if ($chassisId === null && ($generation === null || $generation === '')) {
            return;
        }

        // If both chassis_id and generation are provided, ensure they match
        if ($chassisId !== null && $generation !== null && $generation !== '') {
            $chassis = VehicleChassis::find($chassisId);
            if ($chassis && strtoupper(trim($chassis->code)) !== strtoupper(trim($generation))) {
                abort(422, 'El chassis_id y la generación apuntan a chasis distintos. Usa solo uno o asegúrate de que coincidan.');
            }
        }

        $modelId = (int) ($modelData['id'] ?? 0);
        $chassis = $this->resolveChassisForModel($generation, $chassisId, $modelId ?: null);

        if ($chassis === null) {
            return; // Will be created fresh — no contradiction possible
        }

        // Reject contradictory years when chassis already has years set
        if ($chassis->year_from !== null && $chassis->year_to !== null) {
            $isOwnedByThisModel = $modelId !== 0
                && (int) $chassis->model_id === $modelId;

            // Skip validation if this model already owns the chassis (it will update the years)
            if (! $isOwnedByThisModel) {
                $modelYearFrom = (int) ($modelData['year_from'] ?? 0);
                $modelYearTo = (int) ($modelData['year_to'] ?? 0);
                $chassisYearFrom = (int) $chassis->year_from;
                $chassisYearTo = (int) $chassis->year_to;

                if ($modelYearTo < $chassisYearFrom || $modelYearFrom > $chassisYearTo) {
                    abort(422, "Los años del modelo ({$modelYearFrom}-{$modelYearTo}) no coinciden con el chasis {$chassis->code} ({$chassisYearFrom}-{$chassisYearTo}).");
                }
            }
        }
    }

    /**
     * Resolve a chassis record by explicit ID or generation code, using scoped lookup.
     * Both validateChassisConsistency() and syncModelChassis() use this to ensure consistency.
     */
    private function resolveChassisForModel(?string $generation, ?int $chassisId, ?int $modelId): ?VehicleChassis
    {
        if ($chassisId !== null) {
            return VehicleChassis::find($chassisId);
        }

        if ($generation === null || trim($generation) === '') {
            return null;
        }

        $normalizedCode = mb_strtolower(trim($generation));
        $baseQuery = fn () => VehicleChassis::whereRaw('LOWER(code) = ?', [$normalizedCode]);

        // Priority 1: chassis owned by or associated with this model
        if ($modelId !== null) {
            $chassis = $baseQuery()
                ->where(function ($q) use ($modelId) {
                    $q->where('model_id', $modelId)
                        ->orWhereHas('models', fn ($sub) => $sub->where('vehicle_models.id', $modelId));
                })
                ->orderBy('id')
                ->first();

            if ($chassis) {
                return $chassis;
            }
        }

        // Priority 2: unclaimed chassis (model_id IS NULL)
        $chassis = $baseQuery()
            ->whereNull('model_id')
            ->orderBy('id')
            ->first();

        if ($chassis) {
            return $chassis;
        }

        // Priority 3: global fallback (backward compat — any chassis with this code)
        return $baseQuery()
            ->orderBy('id')
            ->first();
    }

    /**
     * Sync chassis to model: prefer explicit chassis_id, fallback to generation code lookup.
     */
    private function syncModelChassis(VehicleModel $model, ?string $generation, ?int $chassisId): void
    {
        $chassis = $this->resolveChassisForModel($generation, $chassisId, $model->id);

        if ($chassis === null) {
            // No existing chassis found — create from generation
            if ($generation === null || trim($generation) === '') {
                return;
            }

            $chassis = VehicleChassis::create([
                'code' => strtoupper(trim($generation)),
                'name' => null,
                'model_id' => $model->id,
                'year_from' => $model->year_from ? (string) $model->year_from : null,
                'year_to' => $model->year_to ? (string) $model->year_to : null,
                'notes' => null,
            ]);

            $chassis->models()->syncWithoutDetaching([$model->id]);

            return;
        }

        // Claim ownership if unclaimed
        if ($chassis->model_id === null) {
            $chassis->model_id = $model->id;
        }

        // Propagate model years when this model owns it
        if ((int) $chassis->model_id === (int) $model->id) {
            $chassis->year_from = $model->year_from ? (string) $model->year_from : $chassis->year_from;
            $chassis->year_to = $model->year_to ? (string) $model->year_to : $chassis->year_to;
        }

        $chassis->save();
        $chassis->models()->syncWithoutDetaching([$model->id]);

        // Sync generation field on the model to match the chassis code
        if ($model->generation !== $chassis->code) {
            $model->update(['generation' => $chassis->code]);
        }
    }
}
