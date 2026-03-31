<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductAttribute;
use App\Models\ProductVehicleCompatibility;
use App\Models\OemReference;
use App\Models\Category;
use App\Models\Brand;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;

class ProductService
{
    /**
     * Create a new product with default variant, pricing and optional inventory.
     * 
     * @param array $data Expected structure:
     *   - sku_base: Base SKU for the product
     *   - name: Product name
     *   - description: Product description
     *   - product_line_id: Optional product line
     *   - brand_id: Brand for the default variant
     *   - sku: SKU for the default variant
     *   - upc: UPC for the default variant (optional)
     *   - pricing: Pricing data for the variant
     *   - initial_inventory: Array of inventory records per warehouse
     */
    public function createProduct(array $data): Product
    {
        DB::beginTransaction();
        
        try {
            // sku_base is now optional — only auto-generate if completely absent
            $skuBase = array_key_exists('sku_base', $data) ? $data['sku_base'] : null;
            // If sku_base is explicitly provided (even empty string), respect it
            // If not provided at all, auto-generate from category
            if (!array_key_exists('sku_base', $data)) {
                $skuBase = $this->autoGenerateSku($data['category_id'] ?? null);
            }

            // Auto-generate name if empty (base name = category only)
            $name = $data['name'] ?? null;
            if (empty($name)) {
                $name = $this->autoGenerateName($data['category_id'] ?? null);
            }

            // Create the base product (without brand)
            $product = Product::create([
                'sku_base'       => $skuBase ?: null,
                'alternate_code' => $data['alternate_code'] ?? null,
                'pricing_formula'=> $data['pricing_formula'] ?? null,
                'product_line_id' => $data['product_line_id'] ?? null,
                'category_id'    => $data['category_id'] ?? null,
                'name'           => $name,
                'description'    => $data['description'] ?? null,
                'status'         => $data['status'] ?? 'active',
                'is_universal'   => $data['is_universal'] ?? false,
            ]);

            // Create default variant with brand
            $variant = $this->createVariant($product, [
                'brand_id' => $data['brand_id'],
                'sku' => $data['sku'] ?? $skuBase,
                'upc' => $data['upc'] ?? null,
                'status' => $data['status'] ?? 'active',
                'is_default' => true,
            ]);

            // Create pricing for product
            if (isset($data['pricing'])) {
                $pricing = $product->pricing()->create($data['pricing']);
                $pricing->calculateMargin();
                $pricing->save();
            }

            // Initialize inventory for variant if provided
            if (isset($data['initial_inventory'])) {
                foreach ($data['initial_inventory'] as $inventoryData) {
                    $variant->inventory()->create($inventoryData);
                }
            }

            // Assign attributes to the default VARIANT (not product)
            if (isset($data['attributes'])) {
                $this->assignVariantAttributes($variant, $data['attributes']);
            }

            // Assign vehicle compatibility
            if (!empty($data['vehicle_compatibility'])) {
                foreach ($data['vehicle_compatibility'] as $engineId) {
                    $product->vehicleCompatibility()->firstOrCreate(['engine_id' => $engineId]);
                }
            }

            // Assign VINs
            if (!empty($data['vins'])) {
                foreach ($data['vins'] as $vin) {
                    $product->vins()->firstOrCreate(['vin' => strtoupper(trim($vin))]);
                }
            }

            // Assign OEM / Cross References
            if (!empty($data['oem_references'])) {
                foreach ($data['oem_references'] as $refCode) {
                    $product->oemReferences()->firstOrCreate([
                        'oem_code' => trim($refCode),
                    ]);
                }
            }

            // Update name with vehicle info if vehicles/VINs were provided
            if (!empty($data['vehicle_compatibility']) || !empty($data['vins'])) {
                $this->updateProductNameWithVehicles($product);
            }

            DB::commit();
            
            return $product->fresh(['variants.brand', 'variants.pricing', 'pricing', 'variants.inventory', 'productLine']);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Create a new variant for an existing product.
     */
    public function createVariant(Product $product, array $data): ProductVariant
    {
        $variant = $product->variants()->create([
            'brand_id' => $data['brand_id'],
            'sku' => $data['sku'],
            'upc' => $data['upc'] ?? null,
            'status' => $data['status'] ?? 'active',
            'is_default' => $data['is_default'] ?? false,
        ]);

        return $variant;
    }

    /**
     * Update product information.
     */
    public function updateProduct(Product $product, array $data): Product
    {
        DB::beginTransaction();
        
        try {
            // Update basic product info (only base product fields)
            $updatePayload = [];
            foreach (['name', 'description', 'status', 'product_line_id', 'is_universal',
                      'sku_base', 'alternate_code', 'pricing_formula'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updatePayload[$field] = $data[$field];
                }
            }
            
            if (!empty($updatePayload)) {
                $product->update($updatePayload);
            }

            // Update default variant if provided
            if (isset($data['default_variant_id'])) {
                $defaultVariant = $product->variants()->find($data['default_variant_id']);
                if ($defaultVariant) {
                    // Remove is_default from all variants
                    $product->variants()->update(['is_default' => false]);
                    // Set new default
                    $defaultVariant->update(['is_default' => true]);
                }
            }

            // Update pricing for product if provided
            if (isset($data['pricing'])) {
                $pricing = $product->pricing;
                if ($pricing) {
                    $pricing->update($data['pricing']);
                    $pricing->calculateMargin();
                    $pricing->save();
                } else {
                    $pricing = $product->pricing()->create($data['pricing']);
                    $pricing->calculateMargin();
                    $pricing->save();
                }
            }

            // Update attributes if provided
            if (isset($data['attributes'])) {
                $this->assignAttributes($product, $data['attributes']);
            }

            DB::commit();
            
            return $product->fresh(['variants.brand', 'variants.pricing', 'pricing', 'variants.inventory', 'productLine']);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update a specific variant.
     */
    public function updateVariant(ProductVariant $variant, array $data): ProductVariant
    {
        DB::beginTransaction();
        
        try {
            // Update variant fields
            $variant->update(array_filter([
                'sku' => $data['sku'] ?? $variant->sku,
                'upc' => $data['upc'] ?? $variant->upc,
                'status' => $data['status'] ?? $variant->status,
            ]));



            DB::commit();
            
            return $variant->fresh(['brand', 'inventory']);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Delete product and all variants.
     */
    public function deleteProduct(Product $product): bool
    {
        DB::beginTransaction();
        
        try {
            // Soft delete will cascade to variants due to DB constraints
            $product->delete();
            
            DB::commit();
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Assign attributes to product.
     */
    private function assignAttributes(Product $product, array $attributes): void
    {
        // Remove existing attributes
        $product->attributes()->delete();
        
        // Create new attributes
        foreach ($attributes as $attribute) {
            $product->attributes()->create($attribute);
        }
    }

    /**
     * Get product with all relationships.
     */
    public function getProductDetail(int $productId, array $includes = [], ?int $warehouseId = null): ?Product
    {
        $query = Product::with(['variants.brand', 'variants.pricing', 'pricing', 'defaultVariant.brand', 'defaultVariant.pricing', 'productLine', 'category', 'engine', 'media']);
        
        // Eager load inventory with optional warehouse filtering
        $query->with([
            'variants.inventory' => function($baseQuery) use ($warehouseId) {
                $baseQuery->with('warehouse'); // Always load warehouse details
                if ($warehouseId) {
                    $baseQuery->where('warehouse_id', $warehouseId);
                }
            },
            'inventory' => function($baseQuery) use ($warehouseId) {
                $baseQuery->with('warehouse');
                if ($warehouseId) {
                    $baseQuery->where('warehouse_id', $warehouseId);
                }
            }
        ]);
        
        // Add additional relationships if specified
        foreach ($includes as $include) {
            switch ($include) {
                case 'suppliers':
                    $query->with('variants.suppliers');
                    break;
                case 'attributes':
                    // Load product-level AND all variant-level attributes
                    $query->with(['attributes', 'variants.attributes']);
                    break;
                case 'media':
                    $query->with('media');
                    break;
                case 'oem':
                    $query->with('oemReferences');
                    break;
                case 'alternatives':
                    $query->with(['alternatives', 'oemReferences']);
                    break;
                case 'compatibility':
                    $query->with('vehicleCompatibility.engine.model.make');
                    break;
                case 'vins':
                    $query->with('vins');
                    break;
            }
        }
        
        $product = $query->find($productId);

        // After loading, merge implicit OEM-based cross-references into alternatives
        if ($product && in_array('alternatives', $includes)) {
            $product = $this->mergeOemAlternatives($product);
            $product = $this->mergeCategoryAlternatives($product);
        }

        return $product;
    }

    /**
     * Merge OEM-based (implicit) alternatives into the product's alternatives collection.
     * Products that share any OEM code with $product are treated as cross-references.
     */
    private function mergeOemAlternatives(Product $product): Product
    {
        // Collect all oem_codes for this product
        $oemCodes = $product->oemReferences->pluck('oem_code')->filter()->unique()->values();

        if ($oemCodes->isEmpty()) {
            return $product;
        }

        // Find other products that have any of these oem_codes
        $implicitAlternatives = Product::with(['defaultVariant.brand', 'pricing', 'oemReferences'])
            ->where('id', '!=', $product->id)
            ->whereHas('oemReferences', function ($q) use ($oemCodes) {
                $q->whereIn('oem_code', $oemCodes);
            })
            ->get();

        // Build the merged collection starting with manual alternatives
        $existing = $product->alternatives ?? collect();

        // IDs already in explicit alternatives
        $existingIds = $existing->pluck('id')->toArray();

        // Mark implicit ones with a synthetic pivot and merge
        $implicitAlternatives->each(function (Product $alt) use ($oemCodes, &$existing, &$existingIds) {
            if (in_array($alt->id, $existingIds)) {
                return; // Already listed explicitly — skip duplicate
            }

            // Find matching OEM codes to build a human-readable source label
            $sharedCodes = $alt->oemReferences
                ->pluck('oem_code')
                ->intersect($oemCodes)
                ->values();

            // Attach a synthetic pivot so the frontend sees it the same way
            $alt->setRelation('pivot', (object) [
                'relationship_type' => 'cross_reference',
                'source'            => 'oem',
                'shared_codes'      => $sharedCodes->implode(', '),
            ]);

            $existing->push($alt);
            $existingIds[] = $alt->id;
        });

        $product->setRelation('alternatives', $existing);

        return $product;
    }

    /**
     * Merge category-based siblings into the alternatives collection.
     * Products in the same category (or subcategory) are treated as category siblings.
     */
    private function mergeCategoryAlternatives(Product $product): Product
    {
        if (!$product->category_id) {
            return $product;
        }

        $existing = $product->alternatives ?? collect();
        $existingIds = $existing->pluck('id')->push($product->id)->toArray();

        $categorySiblings = Product::with(['defaultVariant.brand', 'pricing'])
            ->where('category_id', $product->category_id)
            ->whereNotIn('id', $existingIds)
            ->limit(20)
            ->get();

        $categorySiblings->each(function (Product $alt) use (&$existing) {
            $alt->setRelation('pivot', (object) [
                'relationship_type' => 'category_sibling',
                'source'            => 'category',
                'shared_codes'      => '',
            ]);
            $existing->push($alt);
        });

        $product->setRelation('alternatives', $existing);

        return $product;
    }

    /**
     * Auto-generate a sequential SKU based on category prefix.
     * Format: [PREFIX]-[0001] where PREFIX = first 3 uppercase letters of category name.
     */
    public function autoGenerateSku(?int $categoryId): string
    {
        $prefix = 'GEN';

        if ($categoryId) {
            $category = Category::find($categoryId);
            if ($category) {
                $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $category->name), 0, 3));
                if (strlen($prefix) < 3) {
                    $prefix = str_pad($prefix, 3, 'X');
                }
            }
        }

        // Fetch SKUs to find the max number safely in PHP
    $skus = Product::where('sku_base', 'LIKE', $prefix . '-%')
        ->pluck('sku_base');

    $maxNumber = 0;
    foreach ($skus as $sku) {
        $parts = explode('-', $sku);
        $lastPart = end($parts);
        if (is_numeric($lastPart)) {
            $num = (int)$lastPart;
            if ($num > $maxNumber) {
                $maxNumber = $num;
            }
        }
    }

    $nextNumber = $maxNumber + 1;

    return $prefix . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Auto-generate product BASE name from Category only.
     * Brand and attributes belong to variants, not the base product.
     */
    private function autoGenerateName(?int $categoryId): string
    {
        if ($categoryId) {
            $category = Category::find($categoryId);
            if ($category) {
                return $category->name;
            }
        }

        return 'Producto sin nombre';
    }

    /**
     * Assign attributes to a variant.
     */
    public function assignVariantAttributes(ProductVariant $variant, array $attributes): void
    {
        // Remove existing variant attributes
        $variant->attributes()->delete();

        // Create new attributes with variant_id
        foreach ($attributes as $attribute) {
            $variant->attributes()->create([
                'product_id' => $variant->product_id,
                'name' => $attribute['name'],
                'value' => $attribute['value'] ?? '',
                'unit' => $attribute['unit'] ?? null,
            ]);
        }
    }

    /**
     * Recalculate the product name including compatible vehicles.
     * 
     * Naming convention depends on the category:
     * - Engine parts (motor, pistón, biela, cigüeñal, etc.):
     *   "Category EngineCode - Make Model YY-YY" (engine code first)
     * - Suspension/general parts:
     *   "Category Make Model YY-YY" (chassis first, engine irrelevant)
     * 
     * Years use 2-digit format (e.g. 00-08 instead of 2000-2008).
     * Capped at 3 unique vehicle labels.
     */
    public function updateProductNameWithVehicles(Product $product): Product
    {
        // 1. Build the base name (Category ONLY)
        $baseName = $this->autoGenerateName($product->category_id);

        // 2. Determine if the category is engine-related
        $isEnginePart = false;
        if ($product->category_id) {
            $category = Category::find($product->category_id);
            if ($category) {
                $catName = mb_strtolower($category->name);
                $engineKeywords = ['motor', 'pistón', 'piston', 'biela', 'cigüeñal', 'cigueñal', 'culata',
                    'válvula', 'valvula', 'árbol de levas', 'arbol de levas', 'empaque', 'junta de culata',
                    'bomba de aceite', 'cadena', 'correa', 'timing', 'engine', 'piston ring', 'anillo'];
                foreach ($engineKeywords as $keyword) {
                    if (str_contains($catName, $keyword)) {
                        $isEnginePart = true;
                        break;
                    }
                }
            }
        }

        // 3. Collect vehicle labels from compatibility records
        $vehicleLabels = collect();
        $explicitVehicles = ProductVehicleCompatibility::where('product_id', $product->id)
            ->with('engine.model.make')
            ->get();

        foreach ($explicitVehicles as $compat) {
            $engine = $compat->engine;
            $model  = $engine?->model;
            $make   = $model?->make;

            if (!$make || !$model) continue;

            $engineCode = $engine->engine_code ?? null;
            $yearFrom   = $model->year_from ? substr((string) $model->year_from, -2) : null;
            $yearTo     = $model->year_to   ? substr((string) $model->year_to,   -2) : null;
            $yearRange  = ($yearFrom && $yearTo) ? " {$yearFrom}-{$yearTo}" : ($yearFrom ? " {$yearFrom}+" : '');

            if ($isEnginePart && $engineCode) {
                // Engine parts: "EngineCode Make Model YY-YY"
                $label = "{$engineCode} {$make->name} {$model->name}{$yearRange}";
            } else {
                // Chassis/general: "Make Model YY-YY"
                $label = "{$make->name} {$model->name}{$yearRange}";
            }

            $vehicleLabels->push($label);
        }

        // Source B: VIN-based cross-reference
        $productVins = $product->vins()->pluck('vin')->toArray();
        if (!empty($productVins)) {
            $vehicleVins = \App\Models\VehicleVin::whereIn('vin', $productVins)
                ->with('engine.model.make')
                ->get();

            foreach ($vehicleVins as $vv) {
                $engine = $vv->engine;
                $model  = $engine?->model;
                $make   = $model?->make;

                if (!$make || !$model) continue;

                $engineCode = $engine->engine_code ?? null;
                $yearFrom   = $model->year_from ? substr((string) $model->year_from, -2) : null;
                $yearTo     = $model->year_to   ? substr((string) $model->year_to,   -2) : null;
                $yearRange  = ($yearFrom && $yearTo) ? " {$yearFrom}-{$yearTo}" : ($yearFrom ? " {$yearFrom}+" : '');

                if ($isEnginePart && $engineCode) {
                    $label = "{$engineCode} {$make->name} {$model->name}{$yearRange}";
                } else {
                    $label = "{$make->name} {$model->name}{$yearRange}";
                }

                $vehicleLabels->push($label);
            }
        }

        // 4. Deduplicate and cap at 3
        $uniqueVehicles = $vehicleLabels->unique()->values();

        if ($uniqueVehicles->isNotEmpty()) {
            $displayed = $uniqueVehicles->take(3)->implode(', ');
            $suffix = $uniqueVehicles->count() > 3 ? ' y más' : '';
            $baseName .= ' - ' . $displayed . $suffix;
        }

        // 5. Save
        $product->update(['name' => $baseName]);

        return $product->fresh();
    }

    /**
     * Search products with variants.
     */
    public function searchProducts(array $filters = [], int $perPage = 15)
    {
        $query = Product::with(['variants.brand', 'variants.pricing', 'pricing', 'productLine', 'category']);
        
        // Filter by status
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        
        // Filter by product line
        if (isset($filters['product_line_id'])) {
            $query->where('product_line_id', $filters['product_line_id']);
        }
        
        // Filter by category
        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        
        // Filter by brand (through variants)
        if (isset($filters['brand_id'])) {
            $query->whereHas('variants', function($q) use ($filters) {
                $q->where('brand_id', $filters['brand_id']);
            });
        }
        
        // Search by name or SKU (base or variant)
        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                  ->orWhere('sku_base', 'ILIKE', "%{$search}%")
                  ->orWhereHas('variants', function ($q2) use ($search) {
                      $q2->where('sku', 'ILIKE', "%{$search}%")
                         ->orWhere('upc', 'ILIKE', "%{$search}%");
                  });
            });
        }
        
        return $query->paginate($perPage);
    }
}
