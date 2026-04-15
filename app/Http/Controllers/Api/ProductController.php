<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignAttributesRequest;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductDetailResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $productService
    ) {}

    /**
     * Display a listing of products.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min($request->get('per_page', 20), 50);

        // Archive filter: 'true' = only trashed, 'all' = withTrashed, default = active only
        $archived = $request->get('archived', 'false');
        if ($archived === 'true') {
            $query = Product::onlyTrashed()->with(['pricing', 'productLine', 'category', 'engine']);
        } elseif ($archived === 'all') {
            $query = Product::withTrashed()->with(['pricing', 'productLine', 'category', 'engine']);
        } else {
            $query = Product::query()->with(['pricing', 'productLine', 'category', 'attributes', 'engine']);
        }

        // Filters
        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('brand_id')) {
            $brandIds = $request->get('brand_id');
            // Support both single value and array
            if (is_array($brandIds)) {
                $query->whereHas('variants', function($q) use ($brandIds) {
                    $q->whereIn('brand_id', $brandIds);
                });
            } else {
                $query->whereHas('variants', function($q) use ($brandIds) {
                    $q->where('brand_id', $brandIds);
                });
            }
        }

        if ($request->has('category_id')) {
            $categoryIds = $request->get('category_id');
            // Support both single value and array
            if (is_array($categoryIds)) {
                $query->whereIn('category_id', $categoryIds);
            } else {
                $query->where('category_id', $categoryIds);
            }
        }

        // Eager load ALL variant relationships together to avoid overwriting
        $warehouseId = $request->get('warehouse_id') ?? $request->user()?->current_warehouse_id;

        // For archived products, variants are also soft-deleted — must withTrashed
        $query->with([
            'variants' => function ($q) use ($archived) {
                if ($archived === 'true' || $archived === 'all') {
                    $q->withTrashed();
                }
            },
            'variants.brand',
            'variants.pricing',
            'variants.attributes',
            'variants.inventory' => function ($q) use ($warehouseId) {
                if ($warehouseId) {
                    $q->where('warehouse_id', $warehouseId);
                }
            },
            'inventory' => function ($q) use ($warehouseId) {
                if ($warehouseId) {
                    $q->where('warehouse_id', $warehouseId);
                }
            },
        ]);

        // ProductLine Filter
        if ($request->has('product_line_id')) {
            $query->where('product_line_id', $request->get('product_line_id'));
        }

        // Explicit VIN Filter
        if ($request->has('vin')) {
            $vinValue = $request->get('vin');
            $query->whereHas('vins', function ($q) use ($vinValue) {
                $q->where('vin', 'ilike', "%{$vinValue}%");
            });
        }

        if ($request->has('search')) {
            $query->search($request->get('search'));
        }

        // OEM Code Filter
        if ($request->has('oem_code')) {
            $oemCode = $request->get('oem_code');
            $query->whereHas('oemReferences', function ($q) use ($oemCode) {
                $q->where('oem_code', $oemCode);
            });
        }

        // Attribute Filter (format: "Value" or "Name:Value")
        if ($request->has('attribute_value')) {
            $attrValue = $request->get('attribute_value');
            if (str_contains($attrValue, ':')) {
                [$name, $value] = explode(':', $attrValue, 2);
                $query->whereHas('attributes', function ($q) use ($name, $value) {
                    $q->where('name', $name)->where('value', $value);
                });
            } else {
                $query->whereHas('attributes', function ($q) use ($attrValue) {
                    $q->where('value', $attrValue);
                });
            }
        }

        // Vehicle Context Filter
        if ($request->has('vehicle_engine_id')) {
            $engineId = $request->get('vehicle_engine_id');

            // 1. Mark Exact Matches
            $query->withExists(['compatibleEngines as is_exact_fit' => function ($q) use ($engineId) {
                $q->where('vehicle_engines.id', $engineId);
            }]);

            // 2. Mark if product has ANY fitment constraints (to distinguish Universal)
            $query->withExists('compatibleEngines as has_any_compatibility');

            // 3. Strict Filter (Default: True)
            // If strict_fitment is 'false' (string) or 0, we skip this.
            // But default to strict to be safe in POS.
            $strictFitment = $request->boolean('strict_fitment', true);

            if ($strictFitment) {
                $query->where(function ($q) use ($engineId) {
                     // Is Exact Fit
                     $q->whereHas('compatibleEngines', function ($sub) use ($engineId) {
                         $sub->where('vehicle_engines.id', $engineId);
                     })
                     // OR Is Universal (Has NO specific engines assigned)
                     ->orWhereDoesntHave('compatibleEngines');
                });
            }
        }

        if ($request->boolean('has_stock')) {
            $query->whereHas('inventory', function ($q) {
                $q->where('stock_available', '>', 0);
            });
        }

        // Dynamic sorting
        $allowedSortFields = ['name', 'sku_base', 'status', 'created_at', 'updated_at'];
        $sortField = $request->get('sortField', 'created_at');
        $sortDirection = $request->get('sortDirection', 'desc');
        // Map legacy 'sku' sort to 'sku_base'
        if ($sortField === 'sku') {
            $sortField = 'sku_base';
        }
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'created_at';
        }
        if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
            $sortDirection = 'desc';
        }
        $query->orderBy($sortField, $sortDirection);

        $products = $query->paginate($perPage);

        // Append fitment_status to resources
        $products->getCollection()->transform(function ($product) {
            // Check if we have context attributes
            if (isset($product->is_exact_fit)) {
                if ($product->is_exact_fit) {
                    $product->fitment_status = 'exact';
                } elseif (!$product->has_any_compatibility) {
                    $product->fitment_status = 'universal';
                } else {
                    $product->fitment_status = 'incompatible'; // Should be filtered out by strict query above
                }
            }
            return $product;
        });

        return ProductResource::collection($products);
    }

    /**
     * Check for potential duplicate products by name or SKU.
     * R20: Alert user when creating products with similar name/SKU.
     *
     * GET /api/v1/products/check-duplicate?name=xxx&sku=yyy
     */
    public function checkDuplicate(Request $request): JsonResponse
    {
        $name = $request->get('name');
        $sku = $request->get('sku');

        // Need at least one search param
        if (empty($name) && empty($sku)) {
            return response()->json(['data' => []]);
        }

        $query = Product::query()
            ->select(['id', 'name', 'sku_base'])
            ->limit(5);

        if (!empty($name) && !empty($sku)) {
            // Search by name OR sku
            $query->where(function ($q) use ($name, $sku) {
                $q->where('name', 'ilike', '%' . $name . '%')
                  ->orWhere('sku_base', 'ilike', $sku);
            });
        } elseif (!empty($name)) {
            $query->where('name', 'ilike', '%' . $name . '%');
        } else {
            // Only SKU
            $query->where('sku_base', 'ilike', $sku);
        }

        $duplicates = $query->get();

        return response()->json(['data' => $duplicates]);
    }

    /**
     * Store a newly created product.
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->createProduct($request->validated());

        return response()->json([
            'data' => new ProductResource($product),
            'message' => 'Producto creado exitosamente',
        ], 201);
    }

    /**
     * Duplicate an existing product with its core relationships.
     */
    public function duplicate(Product $product): JsonResponse
    {
        $duplicatedProduct = $this->productService->duplicateProduct($product);

        return response()->json([
            'data' => new ProductResource($duplicatedProduct),
            'message' => 'Producto duplicado. Ajusta los campos necesarios.',
        ], 201);
    }

    /**
     * GET /api/v1/products/next-sku?category_id=X
     * Returns the next auto-generated SKU for a category.
     */
    public function nextSku(Request $request): JsonResponse
    {
        $categoryId = $request->input('category_id');
        $sku = $this->productService->autoGenerateSku($categoryId ? (int) $categoryId : null);

        return response()->json(['sku' => $sku]);
    }

    /**
     * Display the specified product with detailed information.
     */
    public function show(Request $request, int $id): ProductDetailResource
    {
        $includes = $request->get('include', '');
        $includesArray = $includes ? explode(',', $includes) : [];

        $warehouseId = $request->input('warehouse_id');
        if (is_array($warehouseId)) {
            $warehouseId = reset($warehouseId);
        }
        $warehouseId = filter_var($warehouseId, FILTER_VALIDATE_INT) ?: null;
        
        // Fallback to user's current warehouse if not specified
        if (!$warehouseId) {
            $warehouseId = $request->user()?->current_warehouse_id;
        }

        $product = $this->productService->getProductDetail($id, $includesArray, $warehouseId);

        return new ProductDetailResource($product);
    }

    /**
     * Update the specified product.
     */
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $updatedProduct = $this->productService->updateProduct($product, $request->validated());

        return response()->json([
            'data' => new ProductResource($updatedProduct),
            'message' => 'Producto actualizado correctamente',
        ]);
    }

    /**
     * Remove the specified product (soft delete / archive).
     */
    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json([
            'message' => 'Producto archivado correctamente',
        ]);
    }

    /**
     * Restore a soft-deleted (archived) product.
     */
    public function restore(int $id): JsonResponse
    {
        $product = Product::onlyTrashed()->findOrFail($id);
        $product->restore();

        return response()->json([
            'message' => 'Producto restaurado correctamente',
            'data' => new ProductResource($product),
        ]);
    }

    /**
     * Assign or update attributes for a product.
     */
    public function assignAttributes(AssignAttributesRequest $request, Product $product): JsonResponse
    {
        $this->productService->assignAttributes($product, $request->validated()['attributes']);

        return response()->json([
            'data' => [
                'product_id' => $product->id,
                'attributes_updated' => count($request->validated()['attributes']),
            ],
            'message' => 'Atributos asignados correctamente',
        ]);
    }

    /**
     * Delete a specific attribute from a product.
     */
    public function deleteAttribute(Product $product, int $attributeDefinitionId): JsonResponse
    {
        $deleted = $product->attributes()
            ->where('attribute_definition_id', $attributeDefinitionId)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'message' => 'Atributo no encontrado',
            ], 404);
        }

        return response()->json([
            'message' => 'Atributo eliminado',
        ]);
    }

    /**
     * Assign a supplier to the product.
     */
    public function assignSupplier(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'supplier_cost' => 'required|numeric|min:0',
            'lead_time_days' => 'nullable|integer|min:0',
            'minimum_order_quantity' => 'nullable|integer|min:1',
            'is_preferred' => 'nullable|boolean',
        ]);

        $this->productService->assignSupplier(
            $product,
            $validated['supplier_id'],
            $validated
        );

        return response()->json([
            'message' => 'Proveedor asignado correctamente',
        ]);
    }

    /**
     * Remove a supplier from the product.
     */
    public function removeSupplier(Product $product, int $supplierId): JsonResponse
    {
        $product->suppliers()->detach($supplierId);

        return response()->json([
            'message' => 'Proveedor eliminado del producto',
        ]);
    }

    /**
     * Add vehicle compatibility.
     */
    public function addCompatibility(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'engine_id' => 'required|exists:vehicle_engines,id',
            'notes' => 'nullable|string',
        ]);

        $this->productService->addCompatibility(
            $product,
            $validated['engine_id'],
            $validated['notes'] ?? null
        );

        return response()->json([
            'message' => 'Compatibilidad agregada correctamente',
        ]);
    }

    /**
     * Remove vehicle compatibility.
     */
    public function removeCompatibility(Product $product, int $engineId): JsonResponse
    {
        $product->compatibleEngines()->detach($engineId);

        return response()->json([
            'message' => 'Compatibilidad eliminada',
        ]);
    }

    /**
     * Add OEM reference.
     */
    public function addOemReference(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'oem_code' => 'required|string|max:50',
            'manufacturer' => 'nullable|string|max:100',
        ]);

        $product->oemReferences()->create($validated);

        return response()->json([
            'message' => 'Referencia OEM agregada',
        ], 201);
    }

    /**
     * Remove OEM reference.
     */
    public function removeOemReference(Product $product, int $referenceId): JsonResponse
    {
        $deleted = $product->oemReferences()->where('id', $referenceId)->delete();

        if (!$deleted) {
            return response()->json([
                'message' => 'Referencia no encontrada',
            ], 404);
        }

        return response()->json([
            'message' => 'Referencia OEM eliminada',
        ]);
    }

    /**
     * Add alternative product.
     */
    public function addAlternative(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'alternative_product_id' => 'required|exists:products,id',
            'relationship_type' => 'required|in:compatible,substitute,upgrade',
        ]);

        $product->alternatives()->syncWithoutDetaching([
            $validated['alternative_product_id'] => [
                'relationship_type' => $validated['relationship_type'],
            ]
        ]);

        return response()->json([
            'message' => 'Producto alternativo agregado',
        ]);
    }

    /**
     * Remove alternative product.
     */
    public function removeAlternative(Product $product, int $alternativeId): JsonResponse
    {
        $product->alternatives()->detach($alternativeId);

        return response()->json([
            'message' => 'Producto alternativo eliminado',
        ]);
    }

    /**
     * Get batches for a product.
     */
    public function getBatches(Request $request, Product $product)
    {
        $warehouseId = $request->input('warehouse_id');

        $batches = $product->batches()
            ->when($warehouseId, function ($query) use ($warehouseId) {
                $query->whereHas('warehouseBatches', function ($q) use ($warehouseId) {
                    $q->where('warehouse_id', $warehouseId)
                        ->where('quantity', '>', 0);
                });
            })
            ->with(['warehouseBatches' => function ($query) use ($warehouseId) {
                if ($warehouseId) {
                    $query->where('warehouse_id', $warehouseId);
                }
            }])
            ->get();

        return response()->json([
            'data' => $batches->map(function ($batch) {
                return [
                    'id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'expiry_date' => $batch->expiry_date,
                    'quantity' => $batch->warehouseBatches->sum('quantity'),
                ];
            }),
        ]);
    }
    /**
     * Get all images for a product.
     */
    public function getImages(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        
        $images = $product->media()
            ->where('media_type', 'image')
            ->orderBy('display_order')
            ->get()
            ->map(fn($media) => [
                'id' => $media->id,
                'url' => $media->url,
                'original_filename' => $media->original_filename,
                'display_order' => $media->display_order,
            ]);

        return response()->json(['data' => $images]);
    }

    /**
     * Upload one or more product images.
     */
    public function uploadImage(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'images' => 'required',
            'images.*' => 'image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $product = Product::findOrFail($id);
        
        // Support both single 'image' field and multiple 'images' field
        $files = $request->file('images');
        if (!is_array($files)) {
            // Single file upload (backwards compat with 'image' field)
            $singleFile = $request->file('image') ?? $request->file('images');
            $files = $singleFile ? [$singleFile] : [];
        }

        $maxOrder = $product->media()
            ->where('media_type', 'image')
            ->max('display_order') ?? -1;

        $uploaded = [];

        foreach ($files as $file) {
            $path = $file->store('products', 'public');
            $maxOrder++;

            $media = $product->media()->create([
                'media_type' => 'image',
                'category' => 'photo',
                'file_path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'display_order' => $maxOrder,
            ]);

            $uploaded[] = [
                'id' => $media->id,
                'url' => $media->url,
                'original_filename' => $media->original_filename,
                'display_order' => $media->display_order,
            ];
        }

        // Set first image as product thumbnail if none exists
        if (!$product->image_url && count($uploaded) > 0) {
            $firstMedia = $product->media()
                ->where('media_type', 'image')
                ->orderBy('display_order')
                ->first();
            if ($firstMedia) {
                $product->update(['image_url' => $firstMedia->file_path]);
            }
        }

        return response()->json([
            'message' => count($uploaded) . ' image(s) uploaded successfully',
            'data' => $uploaded,
        ]);
    }

    /**
     * Delete a specific product image.
     */
    public function deleteImage(int $productId, int $mediaId): JsonResponse
    {
        $product = Product::findOrFail($productId);
        $media = $product->media()->where('id', $mediaId)->firstOrFail();
        
        // Delete file from storage
        if (\Illuminate\Support\Facades\Storage::disk('public')->exists($media->file_path)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($media->file_path);
        }

        $wasThumb = ($product->image_url === $media->file_path);
        $media->delete();

        // If deleted image was the thumbnail, promote next one
        if ($wasThumb) {
            $nextImage = $product->media()
                ->where('media_type', 'image')
                ->orderBy('display_order')
                ->first();
            $product->update(['image_url' => $nextImage?->file_path]);
        }

        return response()->json([
            'message' => 'Image deleted successfully',
        ]);
    }

    // ==================== REFERENCES & ALTERNATIVES ====================

    /**
     * GET /api/v1/products/{id}/oem-references
     */
    public function oemReferences(Product $product): JsonResponse
    {
        return response()->json([
            'data' => $product->oemReferences()->get(),
        ]);
    }

    /**
     * GET /api/v1/products/{id}/alternatives
     *
     * Returns explicit alternatives (from pivot table) merged with
     * implicit alternatives (products in the same parent-category branch).
     * Limited to 30 total results.
     */
    public function alternatives(Product $product): JsonResponse
    {
        $limit = 30;

        // 1. Explicit alternatives from pivot table
        $explicit = $product->alternatives()->get()->map(function ($alt) {
            return [
                'id' => $alt->id,
                'sku' => $alt->sku_base,
                'name' => $alt->name,
                'relationship_type' => $alt->pivot->relationship_type,
                'source' => 'manual',
            ];
        });

        $explicitIds = $explicit->pluck('id')->toArray();
        $remaining   = $limit - $explicit->count();

        // 2. Implicit alternatives: products under the same parent category
        $implicit = collect();

        if ($remaining > 0 && $product->category_id) {
            $category = $product->category;

            if ($category) {
                // Determine the parent category id (go up one level if subcategory)
                $parentId = $category->parent_id ?? $category->id;

                // Get all subcategory IDs under the parent (including the parent itself)
                $siblingCategoryIds = \App\Models\Category::where('id', $parentId)
                    ->orWhere('parent_id', $parentId)
                    ->pluck('id')
                    ->toArray();

                // Fetch sibling products, excluding self and already-explicit ones
                $implicit = Product::whereIn('category_id', $siblingCategoryIds)
                    ->where('id', '!=', $product->id)
                    ->whereNotIn('id', $explicitIds)
                    ->where('status', 'active')
                    ->limit($remaining)
                    ->get()
                    ->map(function ($p) {
                        return [
                            'id' => $p->id,
                            'sku' => $p->sku_base,
                            'name' => $p->name,
                            'relationship_type' => 'same_category',
                            'source' => 'category',
                        ];
                    });
            }
        }

        return response()->json([
            'data' => $explicit->concat($implicit)->values(),
        ]);
    }

    // ==================== VIN LINKING ====================

    /**
     * GET /api/v1/products/{id}/vins
     * List all VINs for a product
     */
    public function vins(Product $product): JsonResponse
    {
        return response()->json([
            'data' => $product->vins()->orderBy('vin')->get(),
        ]);
    }

    /**
     * POST /api/v1/products/{id}/vins
     * Add a VIN to a product
     */
    public function addVin(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'vin'   => ['required', 'string', 'min:1', 'max:40', 'regex:/^[A-HJ-NPR-Z0-9]+$/i'],
            'notes' => 'nullable|string|max:255',
        ]);

        $vin = $product->vins()->firstOrCreate(
            ['vin' => strtoupper($validated['vin'])],
            ['notes' => $validated['notes'] ?? null]
        );

        // Regenerate product name with vehicle info from VIN
        $this->productService->updateProductNameWithVehicles($product);

        return response()->json([
            'message' => 'VIN vinculado correctamente',
            'data'    => $vin,
        ], 201);
    }

    /**
     * DELETE /api/v1/products/{id}/vins/{vinId}
     * Remove a VIN from a product
     */
    public function removeVin(Product $product, int $vinId): JsonResponse
    {
        $vin = $product->vins()->findOrFail($vinId);
        $vin->delete();

        // Regenerate product name without the removed VIN's vehicle
        $this->productService->updateProductNameWithVehicles($product);

        return response()->json([
            'message' => 'VIN eliminado correctamente',
        ]);
    }

    /**
     * GET /api/v1/products/{id}/vehicle-compatibility
     * Cross-reference product VINs with vehicle_vins table to return
     * full vehicle compatibility info (make → model → engine → VIN).
     */
    public function vehicleCompatibility(Product $product): JsonResponse
    {
        // Get all VINs linked to this product
        $productVins = $product->vins()->pluck('vin')->toArray();

        if (empty($productVins)) {
            return response()->json(['data' => []]);
        }

        // Look up matching vehicle VINs with eager-loaded engine/model/make
        $vehicleVins = \App\Models\VehicleVin::whereIn('vin', $productVins)
            ->with([
                'engine.model.make',
            ])
            ->get()
            ->map(function ($vv) {
                $engine = $vv->engine;
                $model  = $engine?->model;
                $make   = $model?->make;

                return [
                    'vin'        => $vv->vin,
                    'make'       => $make?->name ?? 'N/A',
                    'model'      => $model?->name ?? 'N/A',
                    'year_from'  => $model?->year_from,
                    'year_to'    => $model?->year_to,
                    'engine'     => $engine?->engine_code ?? 'N/A',
                    'displacement' => $engine?->displacement ?? null,
                ];
            });

        return response()->json(['data' => $vehicleVins]);
    }
}

