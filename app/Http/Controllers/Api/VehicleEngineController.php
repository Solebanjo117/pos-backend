<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VehicleEngineController extends Controller
{
    private const ALLOWED_FUEL_TYPES = [
        'Gasoline',
        'Diesel',
        'Hybrid',
        'Electric',
    ];

    /**
     * Display a listing of vehicle engines.
     */
    public function index(Request $request): JsonResponse
    {
        $query = VehicleEngine::query()->with(['model.make', 'models.make']);

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

        // Search
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('engine_code', 'ilike', "%{$search}%")
                    ->orWhere('displacement', 'ilike', "%{$search}%")
                    ->orWhere('fuel_type', 'ilike', "%{$search}%");
            });
        }

        // Pagination
        $perPage = $request->input('per_page', 50);
        $engines = $query->orderBy('engine_code')->paginate($perPage);
        $data = collect($engines->items())
            ->map(fn (VehicleEngine $item) => $this->serializeEngine($item))
            ->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $engines->currentPage(),
                'last_page' => $engines->lastPage(),
                'per_page' => $engines->perPage(),
                'total' => $engines->total(),
            ],
        ]);
    }

    /**
     * Store a newly created vehicle engine.
     * R21: Motors are independent entities (model_id is optional)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model_id' => 'nullable|exists:vehicle_models,id',
            'model_ids' => 'nullable|array',
            'model_ids.*' => 'integer|exists:vehicle_models,id',
            'engine_code' => 'required|string|max:50',
            'displacement' => 'nullable|string|max:20',
            'fuel_type' => 'nullable|string|max:50',
            'aspiration' => 'nullable|string|max:50',
        ]);

        $modelIds = $this->resolveModelIds($validated, false);
        $validated['engine_code'] = $this->normalizeEngineCode($validated['engine_code'] ?? null);
        $this->assertEngineCodeNotBlank($validated['engine_code']);
        $this->assertEngineCodeHasNoWhitespace($validated['engine_code']);

        if (array_key_exists('fuel_type', $validated)) {
            $validated['fuel_type'] = $this->normalizeFuelType($validated['fuel_type'] ?? null);
            $this->assertFuelTypeIsAllowed($validated['fuel_type']);
        }

        $this->ensureEngineCodeIsUniquePerModel($validated['engine_code'], $modelIds);

        $validated['model_id'] = $modelIds[0] ?? null;
        unset($validated['model_ids']);

        $engine = VehicleEngine::create($validated);

        if (!empty($modelIds)) {
            $engine->models()->sync($modelIds);
        }

        return response()->json([
            'message' => 'Motor creado exitosamente',
            'data' => $this->serializeEngine($engine),
        ], 201);
    }

    /**
     * Display the specified vehicle engine.
     */
    public function show(string $id): JsonResponse
    {
        $engine = VehicleEngine::with(['model.make', 'models.make', 'compatibleProducts'])
            ->findOrFail($id);

        return response()->json(['data' => $this->serializeEngine($engine)]);
    }

    /**
     * Update the specified vehicle engine.
     * R21: Motors are independent entities (model_id is optional)
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $engine = VehicleEngine::findOrFail($id);

        $validated = $request->validate([
            'model_id' => 'nullable|exists:vehicle_models,id',
            'model_ids' => 'nullable|array',
            'model_ids.*' => 'integer|exists:vehicle_models,id',
            'engine_code' => 'sometimes|required|string|max:50',
            'displacement' => 'nullable|string|max:20',
            'fuel_type' => 'nullable|string|max:50',
            'aspiration' => 'nullable|string|max:50',
        ]);

        $modelIds = $this->resolveModelIdsForUpdate($validated);
        $targetModelIds = $modelIds ?? $this->extractModelIdsFromEngine($engine);

        if (array_key_exists('engine_code', $validated)) {
            $validated['engine_code'] = $this->normalizeEngineCode($validated['engine_code'] ?? null);
            $this->assertEngineCodeNotBlank($validated['engine_code']);
            $this->assertEngineCodeHasNoWhitespace($validated['engine_code']);
        }

        if (array_key_exists('fuel_type', $validated)) {
            $validated['fuel_type'] = $this->normalizeFuelType($validated['fuel_type'] ?? null);
            $this->assertFuelTypeIsAllowed($validated['fuel_type']);
        }

        $targetEngineCode = array_key_exists('engine_code', $validated)
            ? $validated['engine_code']
            : $this->normalizeEngineCode($engine->engine_code);

        $this->ensureEngineCodeIsUniquePerModel($targetEngineCode, $targetModelIds, (int) $engine->id);

        if ($modelIds !== null) {
            $validated['model_id'] = $modelIds[0] ?? null;
        } else {
            // Ignore legacy singular model_id updates unless model_ids is explicitly sent.
            unset($validated['model_id']);
        }
        unset($validated['model_ids']);

        $engine->update($validated);

        if ($modelIds !== null) {
            $engine->models()->sync($modelIds);
        }

        return response()->json([
            'message' => 'Motor actualizado exitosamente',
            'data' => $this->serializeEngine($engine),
        ]);
    }

    /**
     * Remove the specified vehicle engine.
     */
    public function destroy(string $id): JsonResponse
    {
        $engine = VehicleEngine::findOrFail($id);

        // Check if engine has products
        if ($engine->compatibility()->count() > 0) {
            return response()->json([
                'message' => 'No se puede eliminar el motor porque tiene productos compatibles asociados',
            ], 422);
        }

        $engine->delete();

        return response()->json([
            'message' => 'Motor eliminado exitosamente',
        ]);
    }

    /**
     * Get engines by model ID (helper endpoint).
     */
    public function byModel(string $modelId): JsonResponse
    {
        $id = (int) $modelId;

        $engines = VehicleEngine::with(['model.make', 'models.make'])
            ->where(function ($query) use ($id) {
                $query->where('model_id', $id)
                    ->orWhereHas('models', function ($modelsQuery) use ($id) {
                        $modelsQuery->where('vehicle_models.id', $id);
                    });
            })
            ->orderBy('engine_code')
            ->get()
            ->map(fn (VehicleEngine $item) => $this->serializeEngine($item));

        return response()->json(['data' => $engines]);
    }

    /**
     * Resolve model IDs from legacy/singular and new/plural payload formats.
     *
     * @return array<int>|null
     */
    private function resolveModelIds(array $validated, bool $allowNoChange, bool $fallbackToLegacyWhenModelIdsEmpty = true): ?array
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

            if ($fallbackToLegacyWhenModelIdsEmpty && $hasLegacyModelId && !empty($validated['model_id'])) {
                return [(int) $validated['model_id']];
            }

            return [];
        }

        $legacyModelId = $validated['model_id'] ?? null;

        return !empty($legacyModelId) ? [(int) $legacyModelId] : [];
    }

    /**
     * Resolve model IDs for update without side-effects on legacy payloads.
     *
     * Legacy clients may still send model_id on update. We only mutate pivot
     * associations when model_ids is explicitly provided.
     *
     * @return array<int>|null
     */
    private function resolveModelIdsForUpdate(array $validated): ?array
    {
        if (!array_key_exists('model_ids', $validated)) {
            return null;
        }

        return $this->resolveModelIds($validated, true, false);
    }

    private function normalizeEngineCode(?string $engineCode): string
    {
        return mb_strtoupper(trim((string) $engineCode));
    }

    private function assertEngineCodeNotBlank(string $engineCode): void
    {
        if ($engineCode === '') {
            throw ValidationException::withMessages([
                'engine_code' => ['El codigo del motor es obligatorio.'],
            ]);
        }
    }

    private function assertEngineCodeHasNoWhitespace(string $engineCode): void
    {
        if (preg_match('/\s/u', $engineCode) === 1) {
            throw ValidationException::withMessages([
                'engine_code' => ['El codigo del motor no puede contener espacios.'],
            ]);
        }
    }

    private function normalizeFuelType(?string $fuelType): ?string
    {
        $normalized = trim((string) $fuelType);

        if ($normalized === '') {
            return null;
        }

        foreach (self::ALLOWED_FUEL_TYPES as $allowedFuelType) {
            if (mb_strtolower($allowedFuelType) === mb_strtolower($normalized)) {
                return $allowedFuelType;
            }
        }

        return $normalized;
    }

    private function assertFuelTypeIsAllowed(?string $fuelType): void
    {
        if ($fuelType === null) {
            return;
        }

        if (!in_array($fuelType, self::ALLOWED_FUEL_TYPES, true)) {
            throw ValidationException::withMessages([
                'fuel_type' => ['Tipo de combustible invalido. Valores permitidos: Gasoline, Diesel, Hybrid, Electric.'],
            ]);
        }
    }

    /**
     * @return array<int>
     */
    private function extractModelIdsFromEngine(VehicleEngine $engine): array
    {
        $engine->loadMissing('models:id');

        $modelIds = $engine->models
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (empty($modelIds) && $engine->model_id !== null) {
            $modelIds = [(int) $engine->model_id];
        }

        return $modelIds;
    }

    /**
     * @param  array<int>  $modelIds
     */
    private function ensureEngineCodeIsUniquePerModel(string $engineCode, array $modelIds, ?int $ignoreEngineId = null): void
    {
        $normalizedModelIds = collect($modelIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $query = VehicleEngine::query()
            ->whereRaw('LOWER(TRIM(engine_code)) = ?', [mb_strtolower(trim($engineCode))]);

        if ($ignoreEngineId !== null) {
            $query->where('id', '!=', $ignoreEngineId);
        }

        if (empty($normalizedModelIds)) {
            // Explicit independent-engine strategy: only collide with other independent engines.
            $query->where(function ($scopedQuery) {
                $scopedQuery->whereNull('model_id')
                    ->whereDoesntHave('models');
            });
        } else {
            $query->where(function ($scopedQuery) use ($normalizedModelIds) {
                $scopedQuery->whereIn('model_id', $normalizedModelIds)
                    ->orWhereHas('models', function ($modelsQuery) use ($normalizedModelIds) {
                        $modelsQuery->whereIn('vehicle_models.id', $normalizedModelIds);
                    });
            });
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'engine_code' => ['Ya existe un motor con este codigo para el/los modelo(s) seleccionado(s).'],
            ]);
        }
    }

    private function serializeEngine(VehicleEngine $engine): VehicleEngine
    {
        $engine->loadMissing(['model.make', 'models.make']);

        $modelIds = $engine->models
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (empty($modelIds) && $engine->model_id !== null) {
            $modelIds = [(int) $engine->model_id];
        }

        if ($engine->relationLoaded('model') && $engine->model === null && $engine->models->isNotEmpty()) {
            $engine->setRelation('model', $engine->models->first());
        }

        $engine->setAttribute('model_ids', $modelIds);

        if ($engine->model_id === null && !empty($modelIds)) {
            $engine->setAttribute('model_id', $modelIds[0]);
        }

        return $engine;
    }
}
