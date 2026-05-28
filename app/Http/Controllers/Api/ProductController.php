<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignAttributesRequest;
use App\Http\Requests\ProductProfileRequest;
use App\Http\Requests\RegenerateProductNameRequest;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductDetailResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductVehicleCompatibility;
use App\Services\ProductProfileService;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly ProductProfileService $productProfileService
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
            $query = Product::onlyTrashed()->with(['pricing', 'productLine', 'category', 'extraCategories', 'attributes', 'engine', 'media']);
        } elseif ($archived === 'all') {
            $query = Product::withTrashed()->with(['pricing', 'productLine', 'category', 'extraCategories', 'attributes', 'engine', 'media']);
        } else {
            $query = Product::query()->with(['pricing', 'productLine', 'category', 'extraCategories', 'attributes', 'engine', 'media']);
        }

        // Filters
        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('brand_id')) {
            $brandIds = $request->get('brand_id');
            // Support both single value and array
            if (is_array($brandIds)) {
                $query->whereHas('variants', function ($q) use ($brandIds) {
                    $q->whereIn('brand_id', $brandIds);
                });
            } else {
                $query->whereHas('variants', function ($q) use ($brandIds) {
                    $q->where('brand_id', $brandIds);
                });
            }
        }

        if ($request->has('category_id')) {
            $categoryIds = $request->get('category_id');
            // Support both single value and array
            if (is_array($categoryIds)) {
                $query->where(function ($categoryQuery) use ($categoryIds) {
                    $categoryQuery->whereIn('category_id', $categoryIds)
                        ->orWhereHas('extraCategories', function ($extraQuery) use ($categoryIds) {
                            $extraQuery->whereIn('categories.id', $categoryIds);
                        });
                });
            } else {
                $query->where(function ($categoryQuery) use ($categoryIds) {
                    $categoryQuery->where('category_id', $categoryIds)
                        ->orWhereHas('extraCategories', function ($extraQuery) use ($categoryIds) {
                            $extraQuery->where('categories.id', $categoryIds);
                        });
                });
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
            'variants.suppliers',
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

        $searchTerm = $request->filled('search')
            ? $request->get('search')
            : ($request->filled('sku') ? $request->get('sku') : null);

        $normalizedSearchTokens = collect();
        if (! empty($searchTerm)) {
            $normalizedSearchTokens = collect(preg_split('/\s+/', mb_strtolower(trim((string) $searchTerm))))
                ->filter(fn ($token) => $token !== '');
        }

        if (! empty($searchTerm)) {
            $query->search($searchTerm);
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
                    $q->whereNull('variant_id')
                        ->where('name', $name)
                        ->where('value', $value);
                });
            } else {
                $query->whereHas('attributes', function ($q) use ($attrValue) {
                    $q->whereNull('variant_id')
                        ->where('value', $attrValue);
                });
            }
        }

        // Vehicle Context Filter
        $engineId = $request->has('vehicle_engine_id') ? (int) $request->get('vehicle_engine_id') : null;
        $chassisId = $request->has('vehicle_chassis_id') ? (int) $request->get('vehicle_chassis_id') : null;

        if ($engineId || $chassisId) {
            // Mark exact fit by context (engine and/or chassis).
            if ($engineId) {
                $query->withExists(['compatibleEngines as is_exact_fit_engine' => function ($q) use ($engineId) {
                    $q->where('vehicle_engines.id', $engineId);
                }]);
            }

            if ($chassisId) {
                $query->withExists(['compatibleChassis as is_exact_fit_chassis' => function ($q) use ($chassisId) {
                    $q->where('vehicle_chassis.id', $chassisId);
                }]);
            }

            // Mark if product has ANY explicit fitment constraints (engine and/or chassis).
            $query->withExists('vehicleCompatibility as has_any_compatibility');

            // Strict filter (default true): exact fit OR universal.
            $strictFitment = $request->boolean('strict_fitment', true);
            if ($strictFitment) {
                $query->where(function ($q) use ($engineId, $chassisId) {
                    if ($engineId) {
                        $q->whereHas('compatibleEngines', function ($sub) use ($engineId) {
                            $sub->where('vehicle_engines.id', $engineId);
                        });
                    }

                    if ($chassisId) {
                        if ($engineId) {
                            $q->orWhereHas('compatibleChassis', function ($sub) use ($chassisId) {
                                $sub->where('vehicle_chassis.id', $chassisId);
                            });
                        } else {
                            $q->whereHas('compatibleChassis', function ($sub) use ($chassisId) {
                                $sub->where('vehicle_chassis.id', $chassisId);
                            });
                        }
                    }

                    // Universal product: no explicit compatibility records.
                    $q->orWhereDoesntHave('vehicleCompatibility');
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
        if (! in_array($sortField, $allowedSortFields)) {
            $sortField = 'created_at';
        }
        if (! in_array(strtolower($sortDirection), ['asc', 'desc'])) {
            $sortDirection = 'desc';
        }
        $query->orderBy($sortField, $sortDirection);

        $products = $query->paginate($perPage);

        // Append fitment_status to resources
        $products->getCollection()->transform(function ($product) use ($normalizedSearchTokens) {
            $hasExactFitContext = isset($product->is_exact_fit)
                || isset($product->is_exact_fit_engine)
                || isset($product->is_exact_fit_chassis);

            if ($hasExactFitContext) {
                $isExactFit = (bool) ($product->is_exact_fit ?? false)
                    || (bool) ($product->is_exact_fit_engine ?? false)
                    || (bool) ($product->is_exact_fit_chassis ?? false);

                if ($isExactFit) {
                    $product->fitment_status = 'exact';
                } elseif (! ($product->has_any_compatibility ?? false)) {
                    $product->fitment_status = 'universal';
                } else {
                    $product->fitment_status = 'incompatible'; // Should be filtered out by strict query above
                }
            }

            if ($normalizedSearchTokens->isNotEmpty()) {
                $product->search_match_type = null;
                $product->search_match_system_id = null;
                $product->search_match_variant_id = null;

                $productSystemId = mb_strtolower((string) ($product->system_id ?? ''));
                if ($productSystemId !== '' && $normalizedSearchTokens->contains($productSystemId)) {
                    $product->search_match_type = 'product';
                    $product->search_match_system_id = $product->system_id;
                } else {
                    $matchedVariant = collect($product->variants ?? [])->first(function ($variant) use ($normalizedSearchTokens) {
                        $variantSystemId = mb_strtolower((string) ($variant->system_id ?? ''));

                        return $variantSystemId !== '' && $normalizedSearchTokens->contains($variantSystemId);
                    });

                    if ($matchedVariant) {
                        $product->search_match_type = 'variant';
                        $product->search_match_system_id = $matchedVariant->system_id;
                        $product->search_match_variant_id = $matchedVariant->id;
                    }
                }
            }

            return $product;
        });

        return ProductResource::collection($products);
    }

    /**
     * Check for potential duplicate products by SKU base (primary) or name.
     * GET /api/v1/products/check-duplicate?sku=xxx&name=yyy
     */
    public function checkDuplicate(Request $request): JsonResponse
    {
        $sku = $request->get('sku');
        $name = $request->get('name');

        if (empty($sku)) {
            return response()->json(['data' => []]);
        }

        $query = Product::query()
            ->select(['id', 'name', 'sku_base'])
            ->where('sku_base', 'ilike', '%'.$sku.'%')
            ->limit(5);

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
        if (! $warehouseId) {
            $warehouseId = $request->user()?->current_warehouse_id;
        }

        $product = $this->productService->getProductDetail($id, $includesArray, $warehouseId);

        return new ProductDetailResource($product);
    }

    /**
     * Display the operational 360 profile for a product and its variants.
     */
    public function profile(ProductProfileRequest $request, Product $product): JsonResponse
    {
        return response()->json([
            'data' => $this->productProfileService->profile($product, $request->validated()),
        ]);
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
     * POST /api/v1/products/{product}/regenerate-name
     * Re-generate auto name from category + vehicle compatibility.
     */
    public function regenerateName(RegenerateProductNameRequest $request, Product $product): JsonResponse
    {
        $validated = $request->validated();

        if ($request->hasAny(['category_id', 'extra_category_ids'])) {
            $product = $this->productService->updateProduct($product, $validated);
        }

        $updated = $this->productService->updateProductNameWithVehicles($product);

        return response()->json([
            'data' => new ProductResource($updated),
            'message' => 'Descripción regenerada correctamente',
        ]);
    }

    /**
     * POST /api/v1/products/{product}/preview-name
     * Preview generated name without saving product metadata.
     */
    public function previewName(RegenerateProductNameRequest $request, Product $product): JsonResponse
    {
        return response()->json([
            'data' => [
                'name' => $this->productService->previewProductName($product, $request->validated()),
            ],
            'message' => 'Vista previa generada correctamente',
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

        if (! $deleted) {
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

        if (! $deleted) {
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
            ],
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
    public function getImages(Product $product): JsonResponse
    {
        $product->loadMissing(['media', 'category.media', 'extraCategories.media']);

        $images = collect($product->all_images)
            ->values()
            ->map(fn (array $image, int $index) => [
                'id' => $image['id'] ?? null,
                'url' => $image['url'],
                'original_filename' => $image['original_filename']
                    ?? $image['category_name']
                    ?? 'Imagen',
                'display_order' => $image['display_order'] ?? $index,
                'source' => $image['source'] ?? 'media',
                'source_category_id' => $image['source_category_id'] ?? null,
                'category_name' => $image['category_name'] ?? null,
                'category_media_id' => $image['category_media_id'] ?? null,
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
        if (! is_array($files)) {
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
        if (! $product->image_url && count($uploaded) > 0) {
            $firstMedia = $product->media()
                ->where('media_type', 'image')
                ->orderBy('display_order')
                ->first();
            if ($firstMedia) {
                $product->update(['image_url' => $firstMedia->file_path]);
            }
        }

        return response()->json([
            'message' => count($uploaded).' image(s) uploaded successfully',
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
     * Returns upsale products (compatible, substitute, upgrade).
     * Excludes cross_reference and same_category types.
     */
    public function alternatives(Product $product): JsonResponse
    {
        $upsaleTypes = ['compatible', 'substitute', 'upgrade'];

        $alternatives = $product->alternatives()
            ->wherePivotIn('relationship_type', $upsaleTypes)
            ->get()
            ->map(function ($alt) {
                return [
                    'id' => $alt->id,
                    'sku' => $alt->sku_base,
                    'name' => $alt->name,
                    'relationship_type' => $alt->pivot->relationship_type,
                    'source' => 'manual',
                ];
            });

        return response()->json([
            'data' => $alternatives->values(),
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
            'vin' => ['required', 'string', 'min:1', 'max:40', 'regex:/^[A-HJ-NPR-Z0-9]+$/i'],
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
            'data' => $vin,
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
     * full vehicle compatibility info (make → model → engine → chassis).
     */
    public function vehicleCompatibility(Product $product): JsonResponse
    {
        // Get compatibility records with chassis data
        $compatibility = ProductVehicleCompatibility::where('product_id', $product->id)
            ->with(['make', 'model.make', 'engine.model.make', 'chassis.model.make'])
            ->get()
            ->map(function ($comp) {
                $makeName = $comp->make?->name
                    ?? $comp->model?->make?->name
                    ?? $comp->engine?->model?->make?->name
                    ?? $comp->chassis?->model?->make?->name;
                $modelName = $comp->model?->name
                    ?? $comp->engine?->model?->name
                    ?? $comp->chassis?->model?->name;

                $entry = [
                    'id' => $comp->id,
                    'scope_type' => $comp->scope_type,
                    'make_id' => $comp->make_id,
                    'model_id' => $comp->model_id,
                    'make' => $makeName,
                    'model' => $modelName,
                    'year_range' => $comp->model?->year_range ?? $comp->engine?->model?->year_range ?? ($comp->chassis?->model?->year_range ?? null),
                    'engine' => $comp->engine?->full_description,
                    'chassis' => null,
                    'all_engines' => (bool) $comp->all_engines,
                    'all_chassis' => (bool) $comp->all_chassis,
                    'notes' => $comp->notes,
                ];

                if ($comp->chassis) {
                    $entry['chassis'] = [
                        'id' => $comp->chassis->id,
                        'code' => $comp->chassis->code,
                        'name' => $comp->chassis->name,
                        'full_description' => $comp->chassis->full_description,
                    ];
                }

                return $entry;
            });

        return response()->json(['data' => $compatibility]);
    }
}
