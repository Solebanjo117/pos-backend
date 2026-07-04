<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVehicleCompatibility;
use App\Models\VehicleChassis;
use App\Models\VehicleEngine;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductCompatibilityController extends Controller
{
    public function __construct(
        private ProductService $productService
    ) {}

    /**
     * Get compatibility records for a specific product.
     * Returns both engine and chassis records.
     */
    public function index(string $productId): JsonResponse
    {
        $compatibility = ProductVehicleCompatibility::where('product_id', $productId)
            ->with(['make', 'model.make', 'engine.model.make', 'chassis.model.make'])
            ->get();

        return response()->json(['data' => $compatibility]);
    }

    /**
     * Store a new compatibility record.
     * Accepts make_id, model_id, engine_id, chassis_id, or universal flags.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'scope_type' => 'nullable|in:universal,make,model,chassis,engine,raw',
            'make_id' => 'nullable|exists:vehicle_makes,id',
            'model_id' => 'nullable|exists:vehicle_models,id',
            'engine_id' => 'nullable|exists:vehicle_engines,id',
            'chassis_id' => 'nullable|exists:vehicle_chassis,id',
            'raw_vehicle_text' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:500',
            'all_engines' => 'nullable|boolean',
            'all_chassis' => 'nullable|boolean',
        ]);

        // At least one target is required
        $scopeType = $this->resolveScopeType($validated);

        if ($scopeType === null) {
            return response()->json([
                'message' => 'Se requiere marca, modelo, chasis, motor o compatibilidad universal.',
            ], 422);
        }

        if ($scopeType === 'raw' && trim((string) ($validated['raw_vehicle_text'] ?? '')) === '') {
            return response()->json([
                'message' => 'Se requiere texto de carro para compatibilidad raw.',
            ], 422);
        }

        // Check if compatibility already exists (by engine or chassis)
        $query = ProductVehicleCompatibility::where('product_id', $validated['product_id']);

        if ($scopeType === 'engine') {
            $query->where('engine_id', $validated['engine_id']);
        } elseif ($scopeType === 'chassis') {
            $query->where('chassis_id', $validated['chassis_id']);
        } elseif ($scopeType === 'model') {
            $query->where('scope_type', 'model')->where('model_id', $validated['model_id']);
        } elseif ($scopeType === 'make') {
            $query->where('scope_type', 'make')->where('make_id', $validated['make_id']);
        } elseif ($scopeType === 'universal') {
            $query->where('scope_type', 'universal');
        } elseif ($scopeType === 'raw') {
            $rawKey = mb_strtolower(trim(\Illuminate\Support\Str::ascii((string) preg_replace('/\s+/u', ' ', $validated['raw_vehicle_text'] ?? ''))));
            $query->where('scope_type', 'raw')->where('raw_vehicle_key', $rawKey);
        }

        $existing = $query->first();

        if ($existing) {
            return response()->json([
                'message' => 'Esta compatibilidad ya existe',
                'data' => $existing->load('make', 'model.make', 'engine.model.make', 'chassis.model.make'),
            ], 200);
        }

        $compatibility = ProductVehicleCompatibility::create([
            'product_id' => $validated['product_id'],
            'scope_type' => $scopeType,
            'make_id' => $validated['make_id'] ?? null,
            'model_id' => $validated['model_id'] ?? null,
            'engine_id' => $validated['engine_id'] ?? null,
            'chassis_id' => $validated['chassis_id'] ?? null,
            'raw_vehicle_key' => isset($validated['raw_vehicle_text'])
                ? mb_strtolower(trim(\Illuminate\Support\Str::ascii((string) preg_replace('/\s+/u', ' ', $validated['raw_vehicle_text']))))
                : null,
            'raw_vehicle_text' => $validated['raw_vehicle_text'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'all_engines' => in_array($scopeType, ['universal', 'model'], true) ? (bool) ($validated['all_engines'] ?? false) : false,
            'all_chassis' => in_array($scopeType, ['universal', 'model'], true) ? (bool) ($validated['all_chassis'] ?? false) : false,
        ]);

        // Regenerate product name with vehicle info
        $product = Product::find($validated['product_id']);
        $this->productService->updateProductNameWithVehicles($product);

        return response()->json([
            'message' => 'Compatibilidad agregada exitosamente',
            'data' => $compatibility->load('make', 'model.make', 'engine.model.make', 'chassis.model.make'),
        ], 201);
    }

    /**
     * Update a compatibility record (notes only).
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $compatibility = ProductVehicleCompatibility::findOrFail($id);

        $validated = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        $compatibility->update($validated);

        return response()->json([
            'message' => 'Compatibilidad actualizada exitosamente',
            'data' => $compatibility->load('make', 'model.make', 'engine.model.make', 'chassis.model.make'),
        ]);
    }

    /**
     * Remove a compatibility record.
     */
    public function destroy(string $id): JsonResponse
    {
        $compatibility = ProductVehicleCompatibility::findOrFail($id);
        $productId = $compatibility->product_id;
        $compatibility->delete();

        // Regenerate product name without the removed vehicle
        $product = Product::find($productId);
        if ($product) {
            $this->productService->updateProductNameWithVehicles($product);
        }

        return response()->json([
            'message' => 'Compatibilidad eliminada exitosamente',
        ]);
    }

    /**
     * Search products by vehicle (make, model, engine, or chassis).
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'make_id' => 'nullable|exists:vehicle_makes,id',
            'model_id' => 'nullable|exists:vehicle_models,id',
            'engine_id' => 'nullable|exists:vehicle_engines,id',
            'chassis_id' => 'nullable|exists:vehicle_chassis,id',
            'year' => 'nullable|integer|min:1900',
        ]);

        $context = $this->resolveVehicleContext($validated);
        $query = Product::query()
            ->where(function ($productQuery) use ($context): void {
                $productQuery->where('is_universal', true)
                    ->orWhereHas('vehicleCompatibility', function ($compatibilityQuery) use ($context): void {
                        $this->applyHierarchicalMatch($compatibilityQuery, $context);
                    });
            });

        if ($context['has_context']) {
            $query->select('products.*')
                ->selectRaw($this->fitmentRankSql(), [
                    $context['chassis_id'],
                    $context['engine_id'],
                    $context['model_id'],
                    $context['make_id'],
                ])
                ->orderBy('fitment_rank');
        }

        // Filter by year if provided
        if (isset($validated['year'])) {
            $query->whereHas('vehicleCompatibility.engine.model', function ($q) use ($validated) {
                $q->where('year_from', '<=', $validated['year'])
                    ->where('year_to', '>=', $validated['year']);
            });
        }

        $products = $query->with(['vehicleCompatibility.make', 'vehicleCompatibility.model.make', 'vehicleCompatibility.engine.model.make', 'vehicleCompatibility.chassis.model.make'])
            ->paginate($request->input('per_page', 20));

        collect($products->items())->each(function (Product $product): void {
            $rank = (int) ($product->fitment_rank ?? 99);
            $product->fitment_match_type = match ($rank) {
                1 => 'exact',
                2 => 'model',
                3 => 'make',
                default => $product->is_universal ? 'universal' : 'unknown',
            };
            $product->fitment_match_label = match ($product->fitment_match_type) {
                'exact' => 'Exacto',
                'model' => 'Por modelo',
                'make' => 'Por marca',
                'universal' => 'Universal',
                default => 'Coincidencia',
            };
        });

        return response()->json([
            'data' => $products->items(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveScopeType(array $validated): ?string
    {
        if (! empty($validated['scope_type'])) {
            return $validated['scope_type'];
        }

        return match (true) {
            ! empty($validated['engine_id']) => 'engine',
            ! empty($validated['chassis_id']) => 'chassis',
            ! empty($validated['model_id']) => 'model',
            ! empty($validated['make_id']) => 'make',
            ! empty($validated['raw_vehicle_text']) => 'raw',
            ! empty($validated['all_engines']) || ! empty($validated['all_chassis']) => 'universal',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{make_id: ?int, model_id: ?int, engine_id: ?int, chassis_id: ?int, has_context: bool}
     */
    private function resolveVehicleContext(array $validated): array
    {
        $makeId = isset($validated['make_id']) ? (int) $validated['make_id'] : null;
        $modelId = isset($validated['model_id']) ? (int) $validated['model_id'] : null;
        $engineId = isset($validated['engine_id']) ? (int) $validated['engine_id'] : null;
        $chassisId = isset($validated['chassis_id']) ? (int) $validated['chassis_id'] : null;

        if ($chassisId && ! $modelId) {
            $chassis = VehicleChassis::with('model')->find($chassisId);
            $modelId = $chassis?->model_id;
            $makeId = $makeId ?: $chassis?->model?->make_id;
        }

        if ($engineId && ! $modelId) {
            $engine = VehicleEngine::with('model')->find($engineId);
            $modelId = $engine?->model_id;
            $makeId = $makeId ?: $engine?->model?->make_id;
        }

        return [
            'make_id' => $makeId,
            'model_id' => $modelId,
            'engine_id' => $engineId,
            'chassis_id' => $chassisId,
            'has_context' => (bool) ($makeId || $modelId || $engineId || $chassisId),
        ];
    }

    /**
     * @param  array{make_id: ?int, model_id: ?int, engine_id: ?int, chassis_id: ?int, has_context: bool}  $context
     */
    private function applyHierarchicalMatch($query, array $context): void
    {
        $query->where(function ($nested) use ($context): void {
            // Universal records always match.
            $nested->where('scope_type', 'universal');

            if ($context['make_id']) {
                $nested->orWhere(function ($scope) use ($context): void {
                    $scope->where('scope_type', 'make')->where('make_id', $context['make_id']);
                });
            }

            if ($context['model_id']) {
                // Model-scoped all_engines / all_chassis only match when model_id coincides.
                $nested->orWhere(function ($scope) use ($context): void {
                    $scope->where('scope_type', 'model')->where('model_id', $context['model_id']);
                })->orWhereHas('engine', function ($engine) use ($context): void {
                    $engine->where('model_id', $context['model_id']);
                })->orWhereHas('chassis', function ($chassis) use ($context): void {
                    $chassis->where('model_id', $context['model_id']);
                });
            }

            if ($context['engine_id']) {
                $nested->orWhere('engine_id', $context['engine_id']);
            }

            if ($context['chassis_id']) {
                $nested->orWhere('chassis_id', $context['chassis_id']);
            }
        });
    }

    private function fitmentRankSql(): string
    {
        return <<<'SQL'
CASE
    WHEN EXISTS (
        SELECT 1 FROM product_vehicle_compatibility pvc
        WHERE pvc.product_id = products.id
        AND ((pvc.chassis_id = ?) OR (pvc.engine_id = ?))
    ) THEN 1
    WHEN EXISTS (
        SELECT 1 FROM product_vehicle_compatibility pvc
        WHERE pvc.product_id = products.id
        AND pvc.scope_type = 'model'
        AND pvc.model_id = ?
    ) THEN 2
    WHEN EXISTS (
        SELECT 1 FROM product_vehicle_compatibility pvc
        WHERE pvc.product_id = products.id
        AND pvc.scope_type = 'make'
        AND pvc.make_id = ?
    ) THEN 3
    WHEN products.is_universal = true OR EXISTS (
        SELECT 1 FROM product_vehicle_compatibility pvc
        WHERE pvc.product_id = products.id
        AND pvc.scope_type = 'universal'
    ) THEN 4
    ELSE 9
END AS fitment_rank
SQL;
    }
}
