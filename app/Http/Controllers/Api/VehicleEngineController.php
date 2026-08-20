<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleEngine;
use App\Models\VehicleModel;
use App\Services\VehicleCatalogService;
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

    public function __construct(private VehicleCatalogService $catalogService) {}

    /**
     * Display a listing of vehicle engines.
     */
    public function index(Request $request): JsonResponse
    {
        $query = VehicleEngine::query()->with(['make', 'model.make', 'models.make']);

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
                $q->where('make_id', $makeId)
                    ->orWhereHas('model', function ($legacyModelQuery) use ($makeId) {
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
                $like = '%'.mb_strtolower($search).'%';
                $q->whereRaw('LOWER(engine_code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(displacement, ?)) LIKE ?', ['', $like])
                    ->orWhereRaw('LOWER(COALESCE(fuel_type, ?)) LIKE ?', ['', $like])
                    ->orWhereRaw('LOWER(COALESCE(aspiration, ?)) LIKE ?', ['', $like])
                    ->orWhereHas('make', fn ($makeQuery) => $makeQuery->whereRaw('LOWER(name) LIKE ?', [$like]));
            });
        }

        $sorts = [
            'make' => 'make_id',
            'code' => 'engine_code',
            'displacement' => 'displacement',
            'fuel_type' => 'fuel_type',
        ];
        $sort = $sorts[(string) $request->input('sort')] ?? 'engine_code';
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        $perPage = min(max((int) $request->input('per_page', 20), 1), 500);
        $engines = $query->orderBy($sort, $direction)->orderBy('engine_code')->paginate($perPage);
        $counts = $this->catalogService->configurationCountsByEngine(
            collect($engines->items())->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        );
        $canManage = in_array($request->user()?->role, ['admin', 'manager'], true);
        $data = collect($engines->items())
            ->map(function (VehicleEngine $item) use ($counts, $canManage): VehicleEngine {
                $engine = $this->serializeEngine($item);
                $blockReason = $this->catalogService->engineDeleteBlockReason($engine);
                $engine->setAttribute('vehicles_count', $counts[$engine->id] ?? 0);
                $engine->setAttribute('can_edit', $canManage);
                $engine->setAttribute('can_delete', $canManage && $blockReason === null);
                $engine->setAttribute('delete_block_reason', $blockReason);

                return $engine;
            })
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
            'make_id' => 'nullable|exists:vehicle_makes,id',
            'model_id' => 'nullable|exists:vehicle_models,id',
            'model_ids' => 'nullable|array',
            'model_ids.*' => 'integer|exists:vehicle_models,id',
            'engine_code' => 'required|string|max:50',
            'displacement' => 'nullable|string|max:20',
            'fuel_type' => 'nullable|string|max:50',
            'aspiration' => 'nullable|string|max:50',
        ]);

        $modelIds = $this->resolveModelIds($validated, false);
        $validated['make_id'] = $this->resolveMakeId($validated, $modelIds);
        $this->assertMakeIsPresent($validated['make_id']);
        $this->assertModelsBelongToMake($modelIds, $validated['make_id']);
        $validated['engine_code'] = $this->normalizeEngineCode($validated['engine_code'] ?? null);
        $this->assertEngineCodeNotBlank($validated['engine_code']);

        if (array_key_exists('fuel_type', $validated)) {
            $validated['fuel_type'] = $this->normalizeFuelType($validated['fuel_type'] ?? null);
            $this->assertFuelTypeIsAllowed($validated['fuel_type']);
        }

        $this->ensureEngineCodeIsUniqueForMake($validated['engine_code'], $validated['make_id']);

        $validated['model_id'] = $modelIds[0] ?? null;
        unset($validated['model_ids']);

        $engine = VehicleEngine::create($validated);

        if (! empty($modelIds)) {
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
            'make_id' => 'nullable|exists:vehicle_makes,id',
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
        $targetMakeId = $this->resolveMakeIdForUpdate($validated, $targetModelIds, $engine);
        $this->assertMakeIsPresent($targetMakeId);
        $this->assertModelsBelongToMake($targetModelIds, $targetMakeId);

        if (array_key_exists('engine_code', $validated)) {
            $validated['engine_code'] = $this->normalizeEngineCode($validated['engine_code'] ?? null);
            $this->assertEngineCodeNotBlank($validated['engine_code']);
        }

        if (array_key_exists('fuel_type', $validated)) {
            $validated['fuel_type'] = $this->normalizeFuelType($validated['fuel_type'] ?? null);
            $this->assertFuelTypeIsAllowed($validated['fuel_type']);
        }

        $targetEngineCode = array_key_exists('engine_code', $validated)
            ? $validated['engine_code']
            : $this->normalizeEngineCode($engine->engine_code);

        $this->ensureEngineCodeIsUniqueForMake($targetEngineCode, $targetMakeId, (int) $engine->id);

        $validated['make_id'] = $targetMakeId;

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

        $this->catalogService->deleteEngine($engine);

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

        $engines = VehicleEngine::with(['make', 'model.make', 'models.make'])
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

        if (! $hasModelIds && ! $hasLegacyModelId) {
            return $allowNoChange ? null : [];
        }

        if ($hasModelIds) {
            $modelIds = collect($validated['model_ids'] ?? [])
                ->filter(fn ($id) => $id !== null && $id !== '')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            if (! empty($modelIds)) {
                return $modelIds;
            }

            if ($fallbackToLegacyWhenModelIdsEmpty && $hasLegacyModelId && ! empty($validated['model_id'])) {
                return [(int) $validated['model_id']];
            }

            return [];
        }

        $legacyModelId = $validated['model_id'] ?? null;

        return ! empty($legacyModelId) ? [(int) $legacyModelId] : [];
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
        if (! array_key_exists('model_ids', $validated)) {
            return null;
        }

        return $this->resolveModelIds($validated, true, false);
    }

    /**
     * @param  array<int>  $modelIds
     */
    private function resolveMakeId(array $validated, array $modelIds): ?int
    {
        if (! empty($validated['make_id'])) {
            return (int) $validated['make_id'];
        }

        $firstModelId = $modelIds[0] ?? null;
        if (! $firstModelId) {
            return null;
        }

        return VehicleModel::query()
            ->where('id', $firstModelId)
            ->value('make_id');
    }

    /**
     * @param  array<int>  $targetModelIds
     */
    private function resolveMakeIdForUpdate(array $validated, array $targetModelIds, VehicleEngine $engine): ?int
    {
        if (array_key_exists('make_id', $validated)) {
            return $validated['make_id'] ? (int) $validated['make_id'] : null;
        }

        if ($engine->make_id !== null) {
            return (int) $engine->make_id;
        }

        return $this->resolveMakeId($validated, $targetModelIds);
    }

    private function assertMakeIsPresent(?int $makeId): void
    {
        if ($makeId === null) {
            throw ValidationException::withMessages([
                'make_id' => ['Selecciona la marca de vehiculo del motor.'],
            ]);
        }
    }

    /**
     * @param  array<int>  $modelIds
     */
    private function assertModelsBelongToMake(array $modelIds, int $makeId): void
    {
        if ($modelIds === []) {
            return;
        }

        $invalidModelExists = VehicleModel::query()
            ->whereIn('id', $modelIds)
            ->where('make_id', '!=', $makeId)
            ->exists();

        if ($invalidModelExists) {
            throw ValidationException::withMessages([
                'model_ids' => ['Todos los modelos asociados deben pertenecer a la marca seleccionada.'],
            ]);
        }
    }

    private function normalizeEngineCode(?string $engineCode): string
    {
        $code = mb_strtoupper(trim((string) $engineCode));

        // Collapse multiple whitespace characters (spaces, tabs) into a single space.
        return (string) preg_replace('/\s+/u', ' ', $code);
    }

    private function assertEngineCodeNotBlank(string $engineCode): void
    {
        if ($engineCode === '') {
            throw ValidationException::withMessages([
                'engine_code' => ['El codigo del motor es obligatorio.'],
            ]);
        }
    }

    // Internal spaces are now allowed in engine codes (e.g. GAMMA G4 FA).
    // normalizeEngineCode() already handles leading/trailing whitespace via trim().

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

        if (! in_array($fuelType, self::ALLOWED_FUEL_TYPES, true)) {
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
    private function ensureEngineCodeIsUniqueForMake(string $engineCode, int $makeId, ?int $ignoreEngineId = null): void
    {
        $query = VehicleEngine::query()
            ->whereRaw('LOWER(TRIM(engine_code)) = ?', [mb_strtolower(trim($engineCode))])
            ->where(function ($scope) use ($makeId): void {
                $scope->where('make_id', $makeId)
                    ->orWhereHas('model', function ($modelQuery) use ($makeId): void {
                        $modelQuery->where('make_id', $makeId);
                    })
                    ->orWhereHas('models', function ($modelsQuery) use ($makeId): void {
                        $modelsQuery->where('make_id', $makeId);
                    });
            });

        if ($ignoreEngineId !== null) {
            $query->where('id', '!=', $ignoreEngineId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'engine_code' => ['Ya existe un motor con este codigo para la marca seleccionada.'],
            ]);
        }
    }

    private function serializeEngine(VehicleEngine $engine): VehicleEngine
    {
        $engine->loadMissing(['make', 'model.make', 'models.make']);

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

        if ($engine->model_id === null && ! empty($modelIds)) {
            $engine->setAttribute('model_id', $modelIds[0]);
        }

        if ($engine->make_id === null) {
            $derivedMake = $engine->make
                ?? $engine->model?->make
                ?? $engine->models->first()?->make;

            if ($derivedMake) {
                $engine->setAttribute('make_id', $derivedMake->id);
                $engine->setRelation('make', $derivedMake);
            }
        }

        return $engine;
    }
}
