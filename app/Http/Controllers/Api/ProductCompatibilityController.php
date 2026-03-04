<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductVehicleCompatibility;
use App\Models\Product;
use App\Models\VehicleEngine;
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
     */
    public function index(string $productId): JsonResponse
    {
        $compatibility = ProductVehicleCompatibility::where('product_id', $productId)
            ->with(['engine.model.make'])
            ->get();

        return response()->json(['data' => $compatibility]);
    }

    /**
     * Store a new compatibility record.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'engine_id' => 'required|exists:vehicle_engines,id',
            'notes' => 'nullable|string|max:500',
        ]);

        // Check if compatibility already exists
        $existing = ProductVehicleCompatibility::where('product_id', $validated['product_id'])
            ->where('engine_id', $validated['engine_id'])
            ->first();

        if ($existing) {
            // Update notes if exists, or just return conflict
            // For now, let's treat it as idempotent but warn
            return response()->json([
                'message' => 'Esta compatibilidad ya existe',
                'data' => $existing->load('engine.model.make'),
            ], 200); // 200 OK mostly allows UI to continue smoothly
        }

        $compatibility = ProductVehicleCompatibility::create($validated);

        // Regenerate product name with vehicle info
        $product = Product::find($validated['product_id']);
        $this->productService->updateProductNameWithVehicles($product);

        return response()->json([
            'message' => 'Compatibilidad agregada exitosamente',
            'data' => $compatibility->load('engine.model.make'),
        ], 201);
    }

    /**
     * Update a compatibility record.
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
            'data' => $compatibility->load('engine.model.make'),
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
     * Search products by vehicle (make, model, engine).
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'make_id' => 'nullable|exists:vehicle_makes,id',
            'model_id' => 'nullable|exists:vehicle_models,id',
            'engine_id' => 'nullable|exists:vehicle_engines,id',
            'year' => 'nullable|integer|min:1900',
        ]);

        $query = Product::query();

        if (isset($validated['engine_id'])) {
            // Search by specific engine
            $query->whereHas('vehicleCompatibility', function ($q) use ($validated) {
                $q->where('engine_id', $validated['engine_id']);
            });
        } elseif (isset($validated['model_id'])) {
            // Search by model (all engines of that model)
            $query->whereHas('vehicleCompatibility.engine', function ($q) use ($validated) {
                $q->where('model_id', $validated['model_id']);
            });
        } elseif (isset($validated['make_id'])) {
            // Search by make (all models of that make)
            $query->whereHas('vehicleCompatibility.engine.model', function ($q) use ($validated) {
                $q->where('make_id', $validated['make_id']);
            });
        }

        // Filter by year if provided
        if (isset($validated['year'])) {
            $query->whereHas('vehicleCompatibility.engine.model', function ($q) use ($validated) {
                $q->where('year_from', '<=', $validated['year'])
                  ->where('year_to', '>=', $validated['year']);
            });
        }

        $products = $query->with(['vehicleCompatibility.engine.model.make'])
            ->paginate($request->input('per_page', 20));

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
}
