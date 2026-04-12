<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductVehicleCompatibility;
use App\Models\Product;
use App\Models\VehicleEngine;
use App\Models\VehicleChassis;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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
            ->with(['engine.model.make', 'chassis.model.make'])
            ->get();

        return response()->json(['data' => $compatibility]);
    }

    /**
     * Store a new compatibility record.
     * Accepts engine_id OR chassis_id (at least one required),
     * or all_engines/all_chassis flags for universal compatibility.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id'  => 'required|exists:products,id',
            'engine_id'   => 'nullable|exists:vehicle_engines,id',
            'chassis_id'  => 'nullable|exists:vehicle_chassis,id',
            'notes'       => 'nullable|string|max:500',
            'all_engines' => 'nullable|boolean',
            'all_chassis' => 'nullable|boolean',
        ]);

        // At least one target is required
        $hasEngine  = !empty($validated['engine_id']);
        $hasChassis = !empty($validated['chassis_id']);
        $allEngines = !empty($validated['all_engines']);
        $allChassis = !empty($validated['all_chassis']);

        if (!$hasEngine && !$hasChassis && !$allEngines && !$allChassis) {
            return response()->json([
                'message' => 'Se requiere al menos engine_id, chassis_id, all_engines=true o all_chassis=true',
            ], 422);
        }

        // Check if compatibility already exists (by engine or chassis)
        $query = ProductVehicleCompatibility::where('product_id', $validated['product_id']);

        if ($hasEngine) {
            $query->where('engine_id', $validated['engine_id']);
        } elseif ($hasChassis) {
            $query->where('chassis_id', $validated['chassis_id']);
        } elseif ($allEngines) {
            $query->where('all_engines', true);
        } elseif ($allChassis) {
            $query->where('all_chassis', true);
        }

        $existing = $query->first();

        if ($existing) {
            return response()->json([
                'message' => 'Esta compatibilidad ya existe',
                'data'    => $existing->load('engine.model.make', 'chassis.model.make'),
            ], 200);
        }

        $compatibility = ProductVehicleCompatibility::create([
            'product_id'  => $validated['product_id'],
            'engine_id'   => $validated['engine_id'] ?? null,
            'chassis_id'  => $validated['chassis_id'] ?? null,
            'notes'       => $validated['notes'] ?? null,
            'all_engines' => $validated['all_engines'] ?? false,
            'all_chassis' => $validated['all_chassis'] ?? false,
        ]);

        // Regenerate product name with vehicle info
        $product = Product::find($validated['product_id']);
        $this->productService->updateProductNameWithVehicles($product);

        return response()->json([
            'message' => 'Compatibilidad agregada exitosamente',
            'data'    => $compatibility->load('engine.model.make', 'chassis.model.make'),
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
            'data'    => $compatibility->load('engine.model.make', 'chassis.model.make'),
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
            'make_id'    => 'nullable|exists:vehicle_makes,id',
            'model_id'   => 'nullable|exists:vehicle_models,id',
            'engine_id'  => 'nullable|exists:vehicle_engines,id',
            'chassis_id' => 'nullable|exists:vehicle_chassis,id',
            'year'       => 'nullable|integer|min:1900',
        ]);

        $query = Product::query();

        if (isset($validated['engine_id'])) {
            $query->whereHas('vehicleCompatibility', function ($q) use ($validated) {
                $q->where('engine_id', $validated['engine_id']);
            });
        } elseif (isset($validated['chassis_id'])) {
            $query->whereHas('vehicleCompatibility', function ($q) use ($validated) {
                $q->where('chassis_id', $validated['chassis_id']);
            });
        } elseif (isset($validated['model_id'])) {
            // Search by model (all engines or chassis of that model)
            $query->where(function ($q) use ($validated) {
                $q->whereHas('vehicleCompatibility.engine', function ($eq) use ($validated) {
                    $eq->where('model_id', $validated['model_id']);
                })->orWhereHas('vehicleCompatibility.chassis', function ($cq) use ($validated) {
                    $cq->where('model_id', $validated['model_id']);
                });
            });
        } elseif (isset($validated['make_id'])) {
            // Search by make (all models of that make)
            $query->where(function ($q) use ($validated) {
                $q->whereHas('vehicleCompatibility.engine.model', function ($mq) use ($validated) {
                    $mq->where('make_id', $validated['make_id']);
                })->orWhereHas('vehicleCompatibility.chassis.model', function ($cq) use ($validated) {
                    $cq->where('make_id', $validated['make_id']);
                });
            });
        }

        // Filter by year if provided
        if (isset($validated['year'])) {
            $query->whereHas('vehicleCompatibility.engine.model', function ($q) use ($validated) {
                $q->where('year_from', '<=', $validated['year'])
                  ->where('year_to', '>=', $validated['year']);
            });
        }

        $products = $query->with(['vehicleCompatibility.engine.model.make', 'vehicleCompatibility.chassis.model.make'])
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => $products->items(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page'    => $products->lastPage(),
                'per_page'     => $products->perPage(),
                'total'        => $products->total(),
            ],
        ]);
    }
}
