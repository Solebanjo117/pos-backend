<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductVariant;
use App\Models\ProductVehicleCompatibility;
use App\Models\VehicleChassis;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductService
{
    /**
     * Normalize incoming attribute payload into name/value/unit triplets.
     *
     * For select-type attributes the frontend may send value as an array of
     * selected options.  We keep the array intact through validation and only
     * serialize it to a JSON string at storage time (see assignAttributes).
     */
    private function normalizeAttributes(array $attributes): array
    {
        return collect($attributes)
            ->map(function ($attribute) {
                $name = trim((string) ($attribute['name'] ?? ''));
                $rawValue = $attribute['value'] ?? '';

                // Arrays are kept as-is (multi-select); scalars are trimmed.
                if (is_array($rawValue)) {
                    $value = array_values(array_filter(
                        array_map(fn ($v) => trim((string) $v), $rawValue),
                        fn (string $v) => $v !== ''
                    ));
                    if (count($value) === 0) {
                        $value = '';
                    }
                } else {
                    $value = trim((string) $rawValue);
                }

                $unitRaw = isset($attribute['unit']) ? trim((string) $attribute['unit']) : null;
                $source = ($attribute['source'] ?? 'custom') === 'category' ? 'category' : 'custom';
                $sourceCategoryId = $source === 'category' ? ($attribute['source_category_id'] ?? null) : null;

                if ($name === '' || (is_string($value) && $value === '') || (is_array($value) && count($value) === 0)) {
                    return null;
                }

                return [
                    'name' => $name,
                    'value' => $value,
                    'unit' => $unitRaw !== '' ? $unitRaw : null,
                    'source' => $source,
                    'source_category_id' => $sourceCategoryId !== null && $sourceCategoryId !== ''
                        ? (int) $sourceCategoryId
                        : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Valida que los atributos con source=category cumplan con las restricciones
     * de tipo select definidas en la categoria origen.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function validateInheritedAttributeValues(array $attributes): array
    {
        $categoryIds = collect($attributes)
            ->where('source', 'category')
            ->pluck('source_category_id')
            ->filter()
            ->unique()
            ->values();

        if ($categoryIds->isEmpty()) {
            return $attributes;
        }

        // Cargar definiciones de atributos de todas las categorías involucradas
        $categories = Category::whereIn('id', $categoryIds)->get()->keyBy('id');

        foreach ($attributes as &$attribute) {
            if ($attribute['source'] !== 'category' || ! $attribute['source_category_id']) {
                continue;
            }

            $category = $categories->get($attribute['source_category_id']);
            if (! $category) {
                continue;
            }

            $attributeName = $this->attributeNameKey($attribute['name']);
            $attributeValue = $attribute['value'];

            // Buscar la definición del atributo en la categoría
            foreach (($category->default_attributes ?? []) as $definition) {
                $definitionName = $this->attributeNameKey($definition['name'] ?? '');

                if ($definitionName !== $attributeName) {
                    continue;
                }

                $type = $definition['type'] ?? 'string';
                $options = $definition['options'] ?? null;

                // Validar solo atributos tipo select
                if ($type === 'select' && is_array($options) && count($options) > 0) {
                    // Multiselección: validar cada opción del array
                    $valuesToCheck = is_array($attributeValue)
                        ? $attributeValue
                        : [$attributeValue];

                    foreach ($valuesToCheck as $singleValue) {
                        if (! in_array($singleValue, $options, true)) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'attributes' => [
                                    'El atributo "'.$attribute['name'].'" debe ser uno de: '.implode(', ', $options).'. Valor recibido: "'.$singleValue.'"',
                                ],
                            ]);
                        }
                    }
                }

                break;
            }
        }
        unset($attribute);

        return $attributes;
    }

    private function attributeNameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    private function engineCategoryKeywords(): array
    {
        return [
            'motor',
            'piston',
            'piston ring',
            'anillo',
            'biela',
            'cigueñal',
            'cigüeñal',
            'culata',
            'valvula',
            'válvula',
            'arbol de levas',
            'árbol de levas',
            'empaque',
            'junta de culata',
            'bomba de aceite',
            'cadena',
            'correa',
            'timing',
            'engine',
        ];
    }

    private function categorySetContainsEnginePart(?int $categoryId, array $extraCategoryIds = []): bool
    {
        foreach ($this->categoriesForProductName($categoryId, $extraCategoryIds) as $category) {
            $categoryName = mb_strtolower($category->name);

            foreach ($this->engineCategoryKeywords() as $keyword) {
                if (str_contains($categoryName, $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeExtraCategoryIds(mixed $categoryIds, ?int $primaryCategoryId): array
    {
        if (! is_array($categoryIds)) {
            return [];
        }

        $primaryId = $primaryCategoryId ? (int) $primaryCategoryId : null;

        return collect($categoryIds)
            ->filter(fn ($categoryId) => $categoryId !== null && $categoryId !== '')
            ->map(fn ($categoryId) => (int) $categoryId)
            ->filter(fn (int $categoryId) => $categoryId > 0 && $categoryId !== $primaryId)
            ->unique()
            ->values()
            ->all();
    }

    private function categoriesForProductName(?int $categoryId, array $extraCategoryIds = []): Collection
    {
        $categoryIds = collect([$categoryId, ...$extraCategoryIds])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($categoryIds->isEmpty()) {
            return new Collection;
        }

        return Category::query()
            ->whereIn('id', $categoryIds->all())
            ->orderBy('name')
            ->get();
    }

    private function categoriesForDefaultAttributes(?int $categoryId, array $extraCategoryIds = []): Collection
    {
        $categories = new Collection;

        if ($categoryId) {
            $primaryCategory = Category::find($categoryId);
            if ($primaryCategory) {
                $categories->push($primaryCategory);
            }
        }

        $extras = Category::query()
            ->whereIn('id', $this->normalizeExtraCategoryIds($extraCategoryIds, $categoryId))
            ->orderBy('name')
            ->get();

        return $categories->concat($extras);
    }

    private function defaultAttributesForCategories(?int $categoryId, array $extraCategoryIds = [], iterable $existingAttributes = []): array
    {
        $existingByName = collect($existingAttributes)
            ->mapWithKeys(function ($attribute) {
                $name = is_array($attribute) ? ($attribute['name'] ?? '') : ($attribute->name ?? '');
                $key = $this->attributeNameKey((string) $name);

                if ($key === '') {
                    return [];
                }

                return [
                    $key => [
                        'value' => is_array($attribute) ? ($attribute['value'] ?? '') : ($attribute->value ?? ''),
                        'unit' => is_array($attribute) ? ($attribute['unit'] ?? null) : ($attribute->unit ?? null),
                    ],
                ];
            });

        $seenNames = [];
        $defaults = [];

        foreach ($this->categoriesForDefaultAttributes($categoryId, $extraCategoryIds) as $category) {
            foreach (($category->default_attributes ?? []) as $attribute) {
                $name = trim((string) ($attribute['name'] ?? ''));
                $key = $this->attributeNameKey($name);

                if ($key === '' || isset($seenNames[$key])) {
                    continue;
                }

                $existing = $existingByName->get($key, []);
                $type = $attribute['type'] ?? 'string';
                $options = $attribute['options'] ?? null;

                $defaults[] = [
                    'name' => $name,
                    'value' => $existing['value'] ?? ($attribute['default_value'] ?? $attribute['value'] ?? ''),
                    'unit' => $attribute['unit'] ?? ($existing['unit'] ?? null),
                    'type' => $type,
                    'options' => $options,
                    'source' => 'category',
                    'source_category_id' => $category->id,
                ];
                $seenNames[$key] = true;
            }
        }

        return $defaults;
    }

    private function categoryDefaultAttributesWithCustomValues(Product $product, ?int $categoryId, array $extraCategoryIds = []): array
    {
        $existingAttributes = $product->attributes()
            ->whereNull('variant_id')
            ->get(['name', 'value', 'unit', 'source', 'source_category_id']);

        $defaults = $this->defaultAttributesForCategories($categoryId, $extraCategoryIds, $existingAttributes);
        $defaultKeys = collect($defaults)
            ->map(fn ($attribute) => $this->attributeNameKey($attribute['name']))
            ->all();

        $customAttributes = $existingAttributes
            ->reject(fn ($attribute) => in_array($this->attributeNameKey($attribute->name), $defaultKeys, true))
            ->map(fn ($attribute) => [
                'name' => $attribute->name,
                'value' => $attribute->value,
                'unit' => $attribute->unit,
                'type' => 'string',
                'options' => null,
                'source' => $attribute->source ?? 'custom',
                'source_category_id' => $attribute->source_category_id,
            ])
            ->values()
            ->all();

        return array_merge($defaults, $customAttributes);
    }

    private function mergeMissingCategoryDefaultsIntoAttributes(array $attributes, ?int $categoryId, array $extraCategoryIds = []): array
    {
        $attributesByName = collect($attributes)
            ->mapWithKeys(function (array $attribute) {
                $key = $this->attributeNameKey((string) ($attribute['name'] ?? ''));

                return $key !== '' ? [$key => true] : [];
            });

        $defaults = $this->defaultAttributesForCategories($categoryId, $extraCategoryIds, $attributes);

        foreach ($defaults as $default) {
            $key = $this->attributeNameKey($default['name']);

            if (! $attributesByName->has($key)) {
                $attributes[] = $default;
                $attributesByName->put($key, true);
            }
        }

        return $attributes;
    }

    private function normalizeMoneyValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function syncOemReferences(Product $product, array $references): void
    {
        $normalized = collect($references)
            ->map(function ($reference) {
                $code = trim((string) ($reference['oem_code'] ?? ''));
                $manufacturer = isset($reference['manufacturer'])
                    ? trim((string) $reference['manufacturer'])
                    : null;

                return [
                    'oem_code' => $code,
                    'manufacturer' => $manufacturer !== '' ? $manufacturer : null,
                ];
            })
            ->filter(fn (array $reference) => $reference['oem_code'] !== '')
            ->unique(fn (array $reference) => mb_strtoupper($reference['oem_code']).'|'.mb_strtolower((string) $reference['manufacturer']))
            ->values();

        $product->oemReferences()->delete();

        foreach ($normalized as $reference) {
            $product->oemReferences()->create($reference);
        }
    }

    private function syncAlternatives(Product $product, array $alternatives): void
    {
        $syncPayload = collect($alternatives)
            ->mapWithKeys(function ($alternative) use ($product) {
                $alternativeProductId = (int) ($alternative['alternative_product_id'] ?? $alternative['id'] ?? 0);

                if ($alternativeProductId <= 0 || $alternativeProductId === (int) $product->id) {
                    return [];
                }

                $relationshipType = $alternative['relationship_type'] ?? 'compatible';
                if (! in_array($relationshipType, ['compatible', 'substitute', 'upgrade'], true)) {
                    $relationshipType = 'compatible';
                }

                return [
                    $alternativeProductId => [
                        'relationship_type' => $relationshipType,
                    ],
                ];
            })
            ->all();

        $product->alternatives()->sync($syncPayload);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeCompatibilityData(array $data): array
    {
        $compatibility = is_array($data['vehicle_compatibility'] ?? null)
            ? $data['vehicle_compatibility']
            : [];

        if (array_is_list($compatibility)) {
            $compatibility = [
                'engines' => collect($compatibility)
                    ->filter()
                    ->map(fn ($engineId): array => ['engine_id' => (int) $engineId])
                    ->values()
                    ->all(),
            ];
        }

        if (! empty($data['vehicle_chassis'])) {
            $compatibility['chassis'] = collect($data['vehicle_chassis'])
                ->filter()
                ->map(fn ($chassisId): array => ['chassis_id' => (int) $chassisId])
                ->values()
                ->all();
        }

        if (! empty($data['vehicle_makes'])) {
            $compatibility['makes'] = collect($data['vehicle_makes'])
                ->filter()
                ->map(fn ($makeId): array => ['make_id' => (int) $makeId])
                ->values()
                ->all();
        }

        if (! empty($data['vehicle_models'])) {
            $compatibility['models'] = collect($data['vehicle_models'])
                ->filter()
                ->map(fn ($modelId): array => ['model_id' => (int) $modelId])
                ->values()
                ->all();
        }

        foreach (['all_engines', 'all_chassis', 'is_universal'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $compatibility[$flag] = $data[$flag];
            }
        }

        // Preserve model_id for scoping all_engines / all_chassis to a specific model.
        if (! empty($compatibility['model_id'])) {
            // Already set from structured payload.
        } elseif (! empty($data['model_id'])) {
            $compatibility['model_id'] = (int) $data['model_id'];
        }

        return array_filter($compatibility, fn (mixed $value): bool => $value !== null && $value !== [] && $value !== false);
    }

    private function syncVehicleCompatibility(Product $product, array $compatibility): void
    {
        $product->vehicleCompatibility()->delete();

        $makeIds = collect($compatibility['makes'] ?? [])
            ->pluck('make_id')
            ->merge($compatibility['make_ids'] ?? [])
            ->merge($compatibility['vehicle_makes'] ?? [])
            ->filter()
            ->map(fn ($makeId) => (int) $makeId)
            ->unique()
            ->values();

        foreach ($makeIds as $makeId) {
            $product->vehicleCompatibility()->create([
                'scope_type' => 'make',
                'make_id' => $makeId,
            ]);
        }

        $modelIds = collect($compatibility['models'] ?? [])
            ->pluck('model_id')
            ->merge($compatibility['model_ids'] ?? [])
            ->merge($compatibility['vehicle_models'] ?? [])
            ->filter()
            ->map(fn ($modelId) => (int) $modelId)
            ->unique()
            ->values();

        foreach ($modelIds as $modelId) {
            $model = VehicleModel::query()->find($modelId);
            $product->vehicleCompatibility()->create([
                'scope_type' => 'model',
                'make_id' => $model?->make_id,
                'model_id' => $modelId,
                'all_chassis' => true,
            ]);
        }

        $engineIds = collect($compatibility['engines'] ?? [])
            ->pluck('engine_id')
            ->filter()
            ->map(fn ($engineId) => (int) $engineId)
            ->unique()
            ->values();

        foreach ($engineIds as $engineId) {
            $engine = VehicleEngine::query()->with(['make', 'model.make'])->find($engineId);
            $product->vehicleCompatibility()->create([
                'scope_type' => 'engine',
                'make_id' => $engine?->make_id ?? $engine?->model?->make_id,
                'model_id' => $engine?->model_id,
                'engine_id' => $engineId,
            ]);
        }

        $chassisIds = collect($compatibility['chassis'] ?? [])
            ->pluck('chassis_id')
            ->filter()
            ->map(fn ($chassisId) => (int) $chassisId)
            ->unique()
            ->values();

        foreach ($chassisIds as $chassisId) {
            $chassis = VehicleChassis::query()->with('model')->find($chassisId);
            $product->vehicleCompatibility()->create([
                'scope_type' => 'chassis',
                'make_id' => $chassis?->model?->make_id,
                'model_id' => $chassis?->model_id,
                'chassis_id' => $chassisId,
            ]);
        }

        $scopedModelId = isset($compatibility['model_id']) ? (int) $compatibility['model_id'] : null;
        $scopedMakeId = null;
        if ($scopedModelId) {
            $scopedModel = VehicleModel::query()->find($scopedModelId);
            $scopedMakeId = $scopedModel?->make_id;
        }

        if ((bool) ($compatibility['allEngines'] ?? $compatibility['all_engines'] ?? false)) {
            $product->vehicleCompatibility()->create([
                'scope_type' => $scopedModelId ? 'model' : 'universal',
                'make_id' => $scopedMakeId,
                'model_id' => $scopedModelId,
                'all_engines' => true,
            ]);
        }

        if ((bool) ($compatibility['allChassis'] ?? $compatibility['all_chassis'] ?? false)) {
            $product->vehicleCompatibility()->create([
                'scope_type' => $scopedModelId ? 'model' : 'universal',
                'make_id' => $scopedMakeId,
                'model_id' => $scopedModelId,
                'all_chassis' => true,
            ]);
        }
    }

    private function normalizePercentValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    private function normalizePricingPayload(array $pricingData): array
    {
        $hasCostBase = array_key_exists('cost_base', $pricingData);
        $hasLegacyCost = array_key_exists('cost', $pricingData);
        $hasCostMiscellaneous = array_key_exists('cost_miscellaneous', $pricingData);

        $costBase = $this->normalizeMoneyValue($pricingData['cost_base'] ?? null);
        $legacyCost = $this->normalizeMoneyValue($pricingData['cost'] ?? null);
        $costMiscellaneous = $this->normalizeMoneyValue($pricingData['cost_miscellaneous'] ?? null);
        $costTaxRate = $this->normalizePercentValue($pricingData['cost_tax_rate'] ?? null) ?? 0;

        if ($hasCostBase) {
            $pricingData['cost_base'] = $costBase;
        }

        if ($hasCostMiscellaneous) {
            $pricingData['cost_miscellaneous'] = $costMiscellaneous ?? 0;
        }

        $effectiveCostBase = $hasCostBase ? $pricingData['cost_base'] : null;
        $effectiveCostMiscellaneous = $pricingData['cost_miscellaneous'] ?? 0;

        if ($effectiveCostBase !== null && is_numeric($effectiveCostBase)) {
            $pricingData['cost'] = (float) round(
                ((float) $effectiveCostBase * (1 + ($costTaxRate / 100))) + (float) $effectiveCostMiscellaneous,
                2,
            );
        } elseif ($hasLegacyCost && $legacyCost !== null) {
            $pricingData['cost'] = $legacyCost;
        }

        return $pricingData;
    }

    /**
     * Create a new product with default variant, pricing and optional inventory.
     *
     * @param  array  $data  Expected structure:
     *                       - sku_base: Base SKU for the product
     *                       - name: Product name
     *                       - description: Product description
     *                       - product_line_id: Optional product line
     *                       - brand_id: Brand for the default variant
     *                       - sku: SKU for the default variant
     *                       - upc: UPC for the default variant (optional)
     *                       - pricing: Pricing data for the variant
     *                       - initial_inventory: Array of inventory records per warehouse
     */
    public function createProduct(array $data): Product
    {
        DB::beginTransaction();

        try {
            $primaryCategoryId = $data['category_id'] ?? null;
            $extraCategoryIds = $this->normalizeExtraCategoryIds($data['extra_category_ids'] ?? [], $primaryCategoryId);
            // sku_base is required — auto-generate if not provided or empty
            $skuBase = $data['sku_base'] ?? null;
            if (empty($skuBase)) {
                $skuBase = $this->autoGenerateSku($primaryCategoryId);
            }

            // Auto-generate name only when not provided.
            $name = $data['name'] ?? null;
            if (is_string($name)) {
                $name = trim($name);
            }

            $nameWasAutoGenerated = empty($name);
            if ($nameWasAutoGenerated) {
                $name = $this->autoGenerateName($primaryCategoryId, $extraCategoryIds);
            }

            // Generate product system_id before creation
            $systemId = $this->generateProductSystemId();

            // Create the base product (without brand)
            $product = Product::create([
                'system_id' => $systemId,
                'sku_base' => $skuBase,
                'alternate_code' => $data['alternate_code'] ?? null,
                'pricing_formula' => $data['pricing_formula'] ?? null,
                'product_line_id' => $data['product_line_id'] ?? null,
                'category_id' => $primaryCategoryId,
                'name' => $name,
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'active',
                'is_universal' => $data['is_universal'] ?? false,
            ]);

            $product->extraCategories()->sync($extraCategoryIds);

            // Create default variant with brand (only if brand_id is provided)
            $variant = null;
            if (! empty($data['brand_id'])) {
                $variant = $this->createVariant($product, [
                    'brand_id' => $data['brand_id'],
                    'sku' => $data['sku'] ?? $skuBase,
                    'upc' => $data['upc'] ?? null,
                    'status' => $data['status'] ?? 'active',
                    'is_default' => true,
                ]);

                // Create pricing for the default variant (not product)
                if (isset($data['pricing'])) {
                    $pricingData = $this->normalizePricingPayload($data['pricing']);
                    $pricingData['variant_id'] = $variant->id;
                    $pricingData['product_id'] = $product->id;
                    $pricing = $variant->pricing()->create($pricingData);
                    $pricing->calculateMargin();
                    $pricing->save();
                }

                // Initialize inventory for variant if provided
                if (isset($data['initial_inventory'])) {
                    foreach ($data['initial_inventory'] as $inventoryData) {
                        $inventoryData['product_id'] = $product->id;
                        if (array_key_exists('stock_available', $inventoryData) && ! array_key_exists('quantity_available', $inventoryData)) {
                            $inventoryData['quantity_available'] = $inventoryData['stock_available'];
                            unset($inventoryData['stock_available']);
                        }
                        $inventory = $variant->inventory()->create($inventoryData);
                        $variant->loadMissing('pricing');

                        app(InventoryFifoService::class)->ensureOpeningLotForInventory(
                            $product->id,
                            $variant->id,
                            (int) $inventory->warehouse_id,
                            (int) $inventory->quantity_available,
                            (float) ($variant->pricing?->cost_total ?? 0)
                        );
                    }
                }
            }

            // Assign attributes at product level, regardless of default variant presence.
            $providedAttributes = isset($data['attributes']) && is_array($data['attributes'])
                ? $this->normalizeAttributes($data['attributes'])
                : [];

            if (empty($providedAttributes)) {
                $providedAttributes = $this->defaultAttributesForCategories($primaryCategoryId, $extraCategoryIds);
            }

            if (! empty($providedAttributes)) {
                $providedAttributes = $this->validateInheritedAttributeValues($providedAttributes);
                $this->assignAttributes($product, $providedAttributes);
            }

            // Assign vehicle compatibility
            $compatibilityPayload = $this->normalizeCompatibilityData($data);
            if ($compatibilityPayload !== []) {
                $this->syncVehicleCompatibility($product, $compatibilityPayload);
            }

            // Assign VINs
            if (! empty($data['vins'])) {
                foreach ($data['vins'] as $vin) {
                    $product->vins()->firstOrCreate(['vin' => strtoupper(trim($vin))]);
                }
            }

            // Assign OEM / Cross References
            if (! empty($data['oem_references'])) {
                foreach ($data['oem_references'] as $refCode) {
                    $product->oemReferences()->firstOrCreate([
                        'oem_code' => trim($refCode),
                    ]);
                }
            }

            // Append vehicle/VIN info only for backend auto-generated names.
            if ($nameWasAutoGenerated && ($compatibilityPayload !== [] || ! empty($data['vins']))) {
                $this->updateProductNameWithVehicles($product);
            }

            DB::commit();

            return $product->fresh(['variants.brand', 'variants.pricing', 'pricing', 'variants.inventory', 'productLine', 'category', 'extraCategories', 'attributes']);
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
        $systemId = $data['system_id'] ?? null;
        if ($systemId === null && ! empty($data['brand_id'])) {
            $systemId = $this->generateVariantSystemId();
        }

        $variant = $product->variants()->create([
            'brand_id' => $data['brand_id'],
            'sku' => $data['sku'],
            'upc' => $data['upc'] ?? null,
            'status' => $data['status'] ?? 'active',
            'is_default' => $data['is_default'] ?? false,
            'system_id' => $systemId,
        ]);

        return $variant;
    }

    /**
     * Create a variant with pricing, aggregate inventory, and an opening lot when initial stock is positive.
     *
     * @param  array<string, mixed>  $data
     */
    public function createVariantWithInitialInventory(Product $product, array $data): ProductVariant
    {
        return DB::transaction(function () use ($product, $data) {
            $sku = $data['sku'] ?? null;
            if (! is_string($sku) || trim($sku) === '') {
                $sku = $this->generateSkuForVariant($product, (int) $data['brand_id']);
            }

            $variant = $this->createVariant($product, [
                'brand_id' => $data['brand_id'],
                'sku' => $sku,
                'upc' => $data['upc'] ?? null,
                'status' => $data['status'] ?? 'active',
                'is_default' => false,
            ]);

            $pricingData = $data['pricing'] ?? null;
            if (! $pricingData) {
                $product->loadMissing('pricing');
                $productPricing = $product->pricing;

                if ($productPricing) {
                    $saleTaxRate = $productPricing->sale_tax_rate ?? $productPricing->tax_rate;
                    $costTaxRate = $productPricing->cost_tax_rate ?? $saleTaxRate;

                    $pricingData = [
                        'base_price' => $productPricing->base_price,
                        'msrp' => $productPricing->msrp,
                        'cost' => (float) $productPricing->getRawOriginal('cost'),
                        'cost_base' => $productPricing->getRawOriginal('cost_base'),
                        'cost_miscellaneous' => $productPricing->getRawOriginal('cost_miscellaneous'),
                        'tax_rate' => $saleTaxRate,
                        'sale_tax_rate' => $saleTaxRate,
                        'cost_tax_rate' => $costTaxRate,
                        'max_discount_percentage' => $productPricing->max_discount_percentage,
                    ];
                }
            }

            if ($pricingData) {
                $pricingData = $this->normalizePricingPayload($pricingData);
                $pricingData['variant_id'] = $variant->id;
                $pricingData['product_id'] = $product->id;
                $pricing = $variant->pricing()->create($pricingData);
                $pricing->calculateMargin();
                $pricing->save();
            }

            $inventoryRows = $data['initial_inventory'] ?? [];
            if (empty($inventoryRows)) {
                $defaultWarehouse = DB::table('warehouses')->first();
                $inventoryRows = $defaultWarehouse ? [[
                    'warehouse_id' => $defaultWarehouse->id,
                    'stock_available' => 0,
                    'reorder_point' => 5,
                    'reorder_quantity' => 10,
                ]] : [];
            }

            $variant->loadMissing('pricing');

            foreach ($inventoryRows as $inventoryData) {
                if (empty($inventoryData['warehouse_id'])) {
                    continue;
                }

                $quantityAvailable = (int) ($inventoryData['stock_available'] ?? $inventoryData['quantity_available'] ?? 0);

                $inventory = $variant->inventory()->create([
                    'warehouse_id' => $inventoryData['warehouse_id'],
                    'quantity_available' => $quantityAvailable,
                    'reorder_point' => $inventoryData['reorder_point'] ?? 0,
                    'reorder_quantity' => $inventoryData['reorder_quantity'] ?? 0,
                    'product_id' => $product->id,
                ]);

                if ($quantityAvailable > 0) {
                    app(InventoryFifoService::class)->ensureOpeningLotForInventory(
                        $product->id,
                        $variant->id,
                        (int) $inventory->warehouse_id,
                        $quantityAvailable,
                        (float) ($variant->pricing?->cost_total ?? 0)
                    );
                }
            }

            return $variant->fresh(['brand', 'pricing', 'inventory.warehouse']);
        });
    }

    private function generateSkuForVariant(Product $product, int $brandId): string
    {
        $base = $product->sku_base ?: ($product->name ? substr($product->name, 0, 10) : 'PROD');
        $brand = Brand::find($brandId);
        $brandCode = $brand ? substr($brand->name, 0, 3) : 'XXX';

        return strtoupper($base.'-'.$brandCode.'-'.substr(uniqid(), -6));
    }

    /**
     * Update product information.
     */
    public function updateProduct(Product $product, array $data): Product
    {
        DB::beginTransaction();

        try {
            $previousCategoryId = $product->category_id;
            $previousExtraCategoryIds = $product->extraCategories()
                ->pluck('categories.id')
                ->map(fn ($categoryId) => (int) $categoryId)
                ->all();

            // Update basic product info (only base product fields)
            $updatePayload = [];
            foreach (
                [
                    'name',
                    'description',
                    'status',
                    'product_line_id',
                    'category_id',
                    'is_universal',
                    'sku_base',
                    'alternate_code',
                    'pricing_formula',
                ] as $field
            ) {
                if (array_key_exists($field, $data)) {
                    $updatePayload[$field] = $data[$field];
                }
            }

            if (! empty($updatePayload)) {
                $product->update($updatePayload);
            }

            $currentCategoryId = array_key_exists('category_id', $data)
                ? $data['category_id']
                : $previousCategoryId;

            $categoryChanged = array_key_exists('category_id', $data)
                && (int) ($data['category_id'] ?? 0) !== (int) ($previousCategoryId ?? 0);
            $extraCategoriesChanged = array_key_exists('extra_category_ids', $data);

            if ($extraCategoriesChanged) {
                $currentExtraCategoryIds = $this->normalizeExtraCategoryIds($data['extra_category_ids'], $currentCategoryId);
                $product->extraCategories()->sync($currentExtraCategoryIds);
            } else {
                $currentExtraCategoryIds = $this->normalizeExtraCategoryIds($previousExtraCategoryIds, $currentCategoryId);

                if ($currentExtraCategoryIds !== $previousExtraCategoryIds) {
                    $product->extraCategories()->sync($currentExtraCategoryIds);
                }
            }

            if (($categoryChanged || $extraCategoriesChanged) && ! isset($data['attributes'])) {
                $newAttributes = $this->categoryDefaultAttributesWithCustomValues($product, $currentCategoryId, $currentExtraCategoryIds);

                if (! empty($newAttributes)) {
                    $this->assignAttributes($product, $newAttributes);
                } else {
                    $product->attributes()->whereNull('variant_id')->delete();
                }
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
                $pricingData = $this->normalizePricingPayload($data['pricing']);
                $pricing = $product->pricing;
                if ($pricing) {
                    $pricing->update($pricingData);
                    $pricing->calculateMargin();
                    $pricing->save();
                } else {
                    $pricing = $product->pricing()->create($pricingData);
                    $pricing->calculateMargin();
                    $pricing->save();
                }
            }

            // Update attributes if provided
            if (isset($data['attributes'])) {
                $normalizedAttributes = $this->normalizeAttributes($data['attributes']);
                $categorySetChanged = $categoryChanged || $extraCategoriesChanged;

                if ($categorySetChanged) {
                    $normalizedAttributes = $this->mergeMissingCategoryDefaultsIntoAttributes(
                        $normalizedAttributes,
                        $currentCategoryId,
                        $currentExtraCategoryIds,
                    );
                }

                $normalizedAttributes = $this->validateInheritedAttributeValues($normalizedAttributes);

                $this->assignAttributes(
                    $product,
                    $normalizedAttributes,
                    preserveUnmentionedInherited: ! $categorySetChanged,
                );
            }

            if (array_key_exists('oem_references', $data)) {
                $this->syncOemReferences($product, $data['oem_references'] ?? []);
            }

            if (array_key_exists('alternatives', $data)) {
                $this->syncAlternatives($product, $data['alternatives'] ?? []);
            }

            if (array_key_exists('vehicle_compatibility', $data)) {
                $this->syncVehicleCompatibility($product, $data['vehicle_compatibility'] ?? []);
            }

            DB::commit();

            return $product->fresh(['variants.brand', 'variants.pricing', 'pricing', 'variants.inventory', 'productLine', 'category', 'extraCategories', 'attributes']);
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
            $variant->update([
                'brand_id' => $data['brand_id'] ?? $variant->brand_id,
                'sku' => $data['sku'] ?? $variant->sku,
                'upc' => $data['upc'] ?? $variant->upc,
                'status' => $data['status'] ?? $variant->status,
            ]);

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
    public function assignAttributes(Product $product, array $attributes, bool $preserveUnmentionedInherited = false): void
    {
        if ($preserveUnmentionedInherited) {
            $incomingKeys = collect($attributes)
                ->map(fn (array $attribute) => $this->attributeNameKey((string) ($attribute['name'] ?? '')))
                ->filter()
                ->flip();

            $preservedInheritedAttributes = $product->attributes()
                ->whereNull('variant_id')
                ->where('source', 'category')
                ->get(['name', 'value', 'unit', 'source', 'source_category_id'])
                ->reject(fn (ProductAttribute $attribute) => $incomingKeys->has($this->attributeNameKey($attribute->name)))
                ->map(fn (ProductAttribute $attribute) => [
                    'name' => $attribute->name,
                    'value' => $attribute->value,
                    'unit' => $attribute->unit,
                    'source' => $attribute->source,
                    'source_category_id' => $attribute->source_category_id,
                ])
                ->all();

            $attributes = array_merge($attributes, $preservedInheritedAttributes);
        }

        // Replace only product-level attributes.
        $product->attributes()->whereNull('variant_id')->delete();

        // Create new product-level attributes.
        foreach ($attributes as $attribute) {
            // Serialize array values (multi-select) to JSON string for storage.
            $value = $attribute['value'];
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }

            $product->attributes()->create([
                'name' => $attribute['name'],
                'value' => $value,
                'unit' => $attribute['unit'] ?? null,
                'variant_id' => null,
                'source' => $attribute['source'] ?? 'custom',
                'source_category_id' => $attribute['source_category_id'] ?? null,
            ]);
        }
    }

    /**
     * Get product with all relationships.
     */
    public function getProductDetail(int $productId, array $includes = [], ?int $warehouseId = null): ?Product
    {
        $query = Product::with([
            'variants.brand',
            'variants.pricing',
            'variants.suppliers',
            'pricing',
            'defaultVariant.brand',
            'defaultVariant.pricing',
            'productLine',
            'category.media',
            'extraCategories.media',
            'engine',
            'media',
        ]);

        // Eager load inventory with optional warehouse filtering
        $query->with([
            'variants.inventory' => function ($baseQuery) use ($warehouseId) {
                $baseQuery->with('warehouse'); // Always load warehouse details
                if ($warehouseId) {
                    $baseQuery->where('warehouse_id', $warehouseId);
                }
            },
            'inventory' => function ($baseQuery) use ($warehouseId) {
                $baseQuery->with('warehouse');
                if ($warehouseId) {
                    $baseQuery->where('warehouse_id', $warehouseId);
                }
            },
        ]);

        // Add additional relationships if specified
        foreach ($includes as $include) {
            switch ($include) {
                case 'suppliers':
                    $query->with('variants.suppliers');
                    break;
                case 'attributes':
                    $query->with('attributes');
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
                    $query->with('vehicleCompatibility.make', 'vehicleCompatibility.model.make', 'vehicleCompatibility.engine.model.make', 'vehicleCompatibility.chassis.model.make');
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
                'source' => 'oem',
                'shared_codes' => $sharedCodes->implode(', '),
            ]);

            $existing->push($alt);
            $existingIds[] = $alt->id;
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
        $skus = Product::where('sku_base', 'LIKE', $prefix.'-%')
            ->pluck('sku_base');

        $maxNumber = 0;
        foreach ($skus as $sku) {
            $parts = explode('-', $sku);
            $lastPart = end($parts);
            if (is_numeric($lastPart)) {
                $num = (int) $lastPart;
                if ($num > $maxNumber) {
                    $maxNumber = $num;
                }
            }
        }

        $nextNumber = $maxNumber + 1;

        return $prefix.'-'.str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Auto-generate product BASE name from Category only.
     * Brand belongs to variants; attributes belong to the base product.
     */
    private function autoGenerateName(?int $categoryId, array $extraCategoryIds = []): string
    {
        $name = $this->categoriesForProductName($categoryId, $extraCategoryIds)
            ->pluck('name')
            ->filter()
            ->implode(' ');

        if ($name !== '') {
            return $name;
        }

        return 'Producto sin nombre';
    }

    private function formatVehicleLabel(?VehicleEngine $engine, ?VehicleChassis $chassis, bool $isEnginePart): ?string
    {
        $model = $engine?->model ?? $chassis?->model;
        $make = $model?->make;

        if (! $make || ! $model) {
            return null;
        }

        $engineCode = $engine?->engine_code;
        $yearFrom = $model->year_from ? substr((string) $model->year_from, -2) : null;
        $yearTo = $model->year_to ? substr((string) $model->year_to, -2) : null;
        $yearRange = ($yearFrom && $yearTo) ? " {$yearFrom}-{$yearTo}" : ($yearFrom ? " {$yearFrom}+" : '');

        if ($isEnginePart && $engineCode) {
            return "{$engineCode} {$make->name} {$model->name}{$yearRange}";
        }

        return "{$make->name} {$model->name}{$yearRange}";
    }

    private function formatVehicleScopeLabel(ProductVehicleCompatibility $compatibility, bool $isEnginePart): ?string
    {
        if ($compatibility->scope_type === 'make') {
            return $compatibility->make?->name;
        }

        if ($compatibility->scope_type === 'model') {
            $model = $compatibility->model;
            $make = $model?->make ?? $compatibility->make;

            return ($make && $model) ? "{$make->name} {$model->name}" : null;
        }

        if ($compatibility->scope_type === 'universal') {
            return 'Universal';
        }

        return $this->formatVehicleLabel($compatibility->engine, $compatibility->chassis, $isEnginePart);
    }

    private function compatibilityVehicleLabels(Product $product, bool $isEnginePart, ?array $draftCompatibility = null): Collection
    {
        if ($draftCompatibility !== null) {
            $labels = collect();

            $makeIds = collect($draftCompatibility['makes'] ?? [])
                ->pluck('make_id')
                ->filter()
                ->map(fn ($makeId) => (int) $makeId)
                ->unique()
                ->values();

            if ($makeIds->isNotEmpty()) {
                VehicleMake::query()
                    ->whereIn('id', $makeIds->all())
                    ->pluck('name')
                    ->each(fn (string $name) => $labels->push($name));
            }

            $modelIds = collect($draftCompatibility['models'] ?? [])
                ->pluck('model_id')
                ->filter()
                ->map(fn ($modelId) => (int) $modelId)
                ->unique()
                ->values();

            if ($modelIds->isNotEmpty()) {
                VehicleModel::query()
                    ->with('make')
                    ->whereIn('id', $modelIds->all())
                    ->get()
                    ->each(function (VehicleModel $model) use ($labels): void {
                        if ($model->make) {
                            $labels->push("{$model->make->name} {$model->name}");
                        }
                    });
            }

            $engineIds = collect($draftCompatibility['engines'] ?? [])
                ->pluck('engine_id')
                ->filter()
                ->map(fn ($engineId) => (int) $engineId)
                ->unique()
                ->values();

            if ($engineIds->isNotEmpty()) {
                VehicleEngine::query()
                    ->with('model.make')
                    ->whereIn('id', $engineIds->all())
                    ->get()
                    ->each(function (VehicleEngine $engine) use ($labels, $isEnginePart) {
                        $label = $this->formatVehicleLabel($engine, null, $isEnginePart);
                        if ($label) {
                            $labels->push($label);
                        }
                    });
            }

            $chassisIds = collect($draftCompatibility['chassis'] ?? [])
                ->pluck('chassis_id')
                ->filter()
                ->map(fn ($chassisId) => (int) $chassisId)
                ->unique()
                ->values();

            if ($chassisIds->isNotEmpty()) {
                VehicleChassis::query()
                    ->with('model.make')
                    ->whereIn('id', $chassisIds->all())
                    ->get()
                    ->each(function (VehicleChassis $chassis) use ($labels, $isEnginePart) {
                        $label = $this->formatVehicleLabel(null, $chassis, $isEnginePart);
                        if ($label) {
                            $labels->push($label);
                        }
                    });
            }

            return $labels;
        }

        return ProductVehicleCompatibility::query()
            ->where('product_id', $product->id)
            ->with(['make', 'model.make', 'engine.model.make', 'chassis.model.make'])
            ->get()
            ->map(fn (ProductVehicleCompatibility $compatibility) => $this->formatVehicleScopeLabel($compatibility, $isEnginePart))
            ->filter()
            ->values();
    }

    private function vinVehicleLabels(Product $product, bool $isEnginePart): Collection
    {
        $productVins = $product->vins()->pluck('vin')->toArray();

        if (empty($productVins)) {
            return collect();
        }

        return \App\Models\VehicleVin::query()
            ->whereIn('vin', $productVins)
            ->with('engine.model.make')
            ->get()
            ->map(fn ($vehicleVin) => $this->formatVehicleLabel($vehicleVin->engine, null, $isEnginePart))
            ->filter()
            ->values();
    }

    private function buildPreviewProductName(Product $product, ?int $categoryId, array $extraCategoryIds = [], ?array $draftCompatibility = null): string
    {
        $baseName = $this->autoGenerateName($categoryId, $extraCategoryIds);
        $isEnginePart = $this->categorySetContainsEnginePart($categoryId, $extraCategoryIds);
        $vehicleLabels = $this->compatibilityVehicleLabels($product, $isEnginePart, $draftCompatibility)
            ->concat($this->vinVehicleLabels($product, $isEnginePart))
            ->unique()
            ->values();

        if ($vehicleLabels->isNotEmpty()) {
            $displayed = $vehicleLabels->take(3)->implode(', ');
            $suffix = $vehicleLabels->count() > 3 ? ' y mas' : '';

            return $baseName.' - '.$displayed.$suffix;
        }

        return $baseName;
    }

    public function previewProductName(Product $product, array $data): string
    {
        $categoryId = array_key_exists('category_id', $data)
            ? ($data['category_id'] ? (int) $data['category_id'] : null)
            : $product->category_id;

        $extraCategoryIds = array_key_exists('extra_category_ids', $data)
            ? $this->normalizeExtraCategoryIds($data['extra_category_ids'] ?? [], $categoryId)
            : $product->extraCategories()
                ->pluck('categories.id')
                ->map(fn ($extraCategoryId) => (int) $extraCategoryId)
                ->all();

        $draftCompatibility = array_key_exists('vehicle_compatibility', $data)
            ? ($data['vehicle_compatibility'] ?? [])
            : null;

        return $this->buildPreviewProductName($product, $categoryId, $extraCategoryIds, $draftCompatibility);
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
        $extraCategoryIds = $product->extraCategories()
            ->pluck('categories.id')
            ->map(fn ($categoryId) => (int) $categoryId)
            ->all();

        // 1. Build the base name (Category ONLY)
        $baseName = $this->autoGenerateName($product->category_id, $extraCategoryIds);

        // 2. Determine if the category is engine-related
        $isEnginePart = false;
        if ($product->category_id) {
            $category = Category::find($product->category_id);
            if ($category) {
                $catName = mb_strtolower($category->name);
                $engineKeywords = [
                    'motor',
                    'pistón',
                    'piston',
                    'biela',
                    'cigüeñal',
                    'cigueñal',
                    'culata',
                    'válvula',
                    'valvula',
                    'árbol de levas',
                    'arbol de levas',
                    'empaque',
                    'junta de culata',
                    'bomba de aceite',
                    'cadena',
                    'correa',
                    'timing',
                    'engine',
                    'piston ring',
                    'anillo',
                ];
                foreach ($engineKeywords as $keyword) {
                    if (str_contains($catName, $keyword)) {
                        $isEnginePart = true;
                        break;
                    }
                }
            }
        }

        if (! $isEnginePart && ! empty($extraCategoryIds)) {
            $engineKeywords = [
                'motor',
                'pistÃ³n',
                'piston',
                'biela',
                'cigÃ¼eÃ±al',
                'cigueÃ±al',
                'culata',
                'vÃ¡lvula',
                'valvula',
                'Ã¡rbol de levas',
                'arbol de levas',
                'empaque',
                'junta de culata',
                'bomba de aceite',
                'cadena',
                'correa',
                'timing',
                'engine',
                'piston ring',
                'anillo',
            ];

            foreach ($this->categoriesForProductName(null, $extraCategoryIds) as $category) {
                $catName = mb_strtolower($category->name);
                foreach ($engineKeywords as $keyword) {
                    if (str_contains($catName, $keyword)) {
                        $isEnginePart = true;
                        break 2;
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
            $model = $engine?->model;
            $make = $model?->make;

            if (! $make || ! $model) {
                continue;
            }

            $engineCode = $engine->engine_code ?? null;
            $yearFrom = $model->year_from ? substr((string) $model->year_from, -2) : null;
            $yearTo = $model->year_to ? substr((string) $model->year_to, -2) : null;
            $yearRange = ($yearFrom && $yearTo) ? " {$yearFrom}-{$yearTo}" : ($yearFrom ? " {$yearFrom}+" : '');

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
        if (! empty($productVins)) {
            $vehicleVins = \App\Models\VehicleVin::whereIn('vin', $productVins)
                ->with('engine.model.make')
                ->get();

            foreach ($vehicleVins as $vv) {
                $engine = $vv->engine;
                $model = $engine?->model;
                $make = $model?->make;

                if (! $make || ! $model) {
                    continue;
                }

                $engineCode = $engine->engine_code ?? null;
                $yearFrom = $model->year_from ? substr((string) $model->year_from, -2) : null;
                $yearTo = $model->year_to ? substr((string) $model->year_to, -2) : null;
                $yearRange = ($yearFrom && $yearTo) ? " {$yearFrom}-{$yearTo}" : ($yearFrom ? " {$yearFrom}+" : '');

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
            $baseName .= ' - '.$displayed.$suffix;
        }

        // 5. Save
        $product->update(['name' => $baseName]);

        return $product->fresh(['category', 'extraCategories']);
    }

    /**
     * Search products with variants.
     */
    public function searchProducts(array $filters = [], int $perPage = 15)
    {
        $query = Product::with(['variants.brand', 'variants.pricing', 'pricing', 'productLine', 'category', 'extraCategories']);

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
            $categoryIds = is_array($filters['category_id']) ? $filters['category_id'] : [$filters['category_id']];
            $query->where(function ($categoryQuery) use ($categoryIds) {
                $categoryQuery->whereIn('category_id', $categoryIds)
                    ->orWhereHas('extraCategories', function ($extraQuery) use ($categoryIds) {
                        $extraQuery->whereIn('categories.id', $categoryIds);
                    });
            });
        }

        // Filter by brand (through variants)
        if (isset($filters['brand_id'])) {
            $query->whereHas('variants', function ($q) use ($filters) {
                $q->where('brand_id', $filters['brand_id']);
            });
        }

        // Search by name or SKU (base or variant)
        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
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

    /**
     * Duplicate a product preserving commercial/technical setup.
     * Excludes inventory, VINs and alternatives.
     */
    public function duplicateProduct(Product $product): Product
    {
        DB::beginTransaction();

        try {
            $source = Product::with([
                'pricing',
                'attributes',
                'productSuppliers',
                'variants.attributes',
                'variants.pricing',
                'variants.suppliers',
                'vehicleCompatibility',
                'oemReferences',
                'media',
                'extraCategories',
            ])->findOrFail($product->id);

            $duplicatedProduct = Product::create([
                'sku_base' => '',
                'pricing_formula' => $source->pricing_formula,
                'product_line_id' => $source->product_line_id,
                'category_id' => $source->category_id,
                'engine_id' => $source->engine_id,
                'name' => $source->name.' (Copia)',
                'description' => $source->description,
                'status' => $source->status,
                'is_universal' => $source->is_universal,
            ]);

            $duplicatedProduct->extraCategories()->sync($source->extraCategories->pluck('id')->all());

            if ($source->pricing) {
                $productSaleTaxRate = $source->pricing->sale_tax_rate ?? $source->pricing->tax_rate;
                $productCostTaxRate = $source->pricing->cost_tax_rate ?? $productSaleTaxRate;

                $productPricingData = [
                    'product_id' => $duplicatedProduct->id,
                    'variant_id' => null,
                    'base_price' => $source->pricing->base_price,
                    'msrp' => $source->pricing->msrp,
                    'cost' => $source->pricing->cost_total,
                    'cost_base' => $source->pricing->getRawOriginal('cost_base'),
                    'cost_miscellaneous' => $source->pricing->cost_miscellaneous,
                    'margin_percentage' => $source->pricing->margin_percentage,
                    'tax_rate' => $productSaleTaxRate,
                    'sale_tax_rate' => $productSaleTaxRate,
                    'cost_tax_rate' => $productCostTaxRate,
                    'tax_type' => $source->pricing->tax_type,
                    'max_discount_percentage' => $source->pricing->max_discount_percentage,
                    'pricing_formula' => $source->pricing->pricing_formula,
                ];

                $pricing = $duplicatedProduct->pricing()->create(
                    $this->normalizePricingPayload($productPricingData)
                );
                $pricing->calculateMargin();
                $pricing->save();
            }

            foreach ($source->variants as $sourceVariant) {
                $duplicatedVariant = $this->createVariant($duplicatedProduct, [
                    'brand_id' => $sourceVariant->brand_id,
                    'sku' => $sourceVariant->sku,
                    'upc' => $sourceVariant->upc,
                    'status' => $sourceVariant->status,
                    'is_default' => (bool) $sourceVariant->is_default,
                ]);

                if ($sourceVariant->pricing) {
                    $variantSaleTaxRate = $sourceVariant->pricing->sale_tax_rate ?? $sourceVariant->pricing->tax_rate;
                    $variantCostTaxRate = $sourceVariant->pricing->cost_tax_rate ?? $variantSaleTaxRate;

                    $variantPricingData = [
                        'product_id' => $duplicatedProduct->id,
                        'variant_id' => $duplicatedVariant->id,
                        'base_price' => $sourceVariant->pricing->base_price,
                        'msrp' => $sourceVariant->pricing->msrp,
                        'cost' => $sourceVariant->pricing->cost_total,
                        'cost_base' => $sourceVariant->pricing->getRawOriginal('cost_base'),
                        'cost_miscellaneous' => $sourceVariant->pricing->cost_miscellaneous,
                        'margin_percentage' => $sourceVariant->pricing->margin_percentage,
                        'tax_rate' => $variantSaleTaxRate,
                        'sale_tax_rate' => $variantSaleTaxRate,
                        'cost_tax_rate' => $variantCostTaxRate,
                        'tax_type' => $sourceVariant->pricing->tax_type,
                        'max_discount_percentage' => $sourceVariant->pricing->max_discount_percentage,
                        'pricing_formula' => $sourceVariant->pricing->pricing_formula,
                    ];

                    $variantPricing = $duplicatedVariant->pricing()->create(
                        $this->normalizePricingPayload($variantPricingData)
                    );
                    $variantPricing->calculateMargin();
                    $variantPricing->save();
                }

                foreach ($sourceVariant->attributes as $attribute) {
                    $duplicatedVariant->attributes()->create([
                        'product_id' => $duplicatedProduct->id,
                        'name' => $attribute->name,
                        'value' => $attribute->value,
                        'unit' => $attribute->unit,
                    ]);
                }

                foreach ($sourceVariant->suppliers as $supplier) {
                    $duplicatedVariant->suppliers()->syncWithoutDetaching([
                        $supplier->id => [
                            'supplier_cost' => $supplier->pivot->supplier_cost ?? 0,
                            'lead_time_days' => $supplier->pivot->lead_time_days ?? null,
                            'minimum_order_quantity' => $supplier->pivot->minimum_order_quantity ?? null,
                            'is_preferred' => (bool) ($supplier->pivot->is_preferred ?? false),
                        ],
                    ]);
                }
            }

            // Duplicate product-level attributes (not variant-level)
            $productLevelAttributes = collect($source->attributes)
                ->filter(fn (ProductAttribute $a) => $a->variant_id === null)
                ->values();
            if ($productLevelAttributes->isNotEmpty()) {
                $this->assignAttributes(
                    $duplicatedProduct,
                    $productLevelAttributes->map(fn (ProductAttribute $attribute) => [
                        'name' => $attribute->name,
                        'value' => $attribute->value,
                        'unit' => $attribute->unit,
                        'source' => $attribute->source ?? 'custom',
                        'source_category_id' => $attribute->source_category_id,
                    ])->toArray()
                );
            }

            foreach ($source->productSuppliers as $productSupplier) {
                $duplicatedProduct->productSuppliers()->create([
                    'supplier_id' => $productSupplier->supplier_id,
                    'supplier_cost' => $productSupplier->supplier_cost,
                    'lead_time_days' => $productSupplier->lead_time_days,
                    'minimum_order_quantity' => $productSupplier->minimum_order_quantity,
                    'is_preferred' => (bool) $productSupplier->is_preferred,
                ]);
            }

            foreach ($source->vehicleCompatibility as $compatibility) {
                $duplicatedProduct->vehicleCompatibility()->create([
                    'scope_type' => $compatibility->scope_type,
                    'make_id' => $compatibility->make_id,
                    'model_id' => $compatibility->model_id,
                    'engine_id' => $compatibility->engine_id,
                    'chassis_id' => $compatibility->chassis_id,
                    'notes' => $compatibility->notes,
                    'all_engines' => (bool) $compatibility->all_engines,
                    'all_chassis' => (bool) $compatibility->all_chassis,
                ]);
            }

            foreach ($source->oemReferences as $oemReference) {
                $duplicatedProduct->oemReferences()->create([
                    'oem_code' => $oemReference->oem_code,
                    'manufacturer' => $oemReference->manufacturer,
                ]);
            }

            foreach ($source->media as $media) {
                $duplicatedProduct->media()->create([
                    'media_type' => $media->media_type,
                    'category' => $media->category,
                    'file_path' => $media->file_path,
                    'original_filename' => $media->original_filename,
                    'file_size' => $media->file_size,
                    'display_order' => $media->display_order,
                ]);
            }

            DB::commit();

            return $duplicatedProduct->fresh([
                'variants.brand',
                'variants.pricing',
                'pricing',
                'productLine',
                'category',
                'extraCategories',
                'attributes',
                'productSuppliers',
                'variants.attributes',
                'variants.suppliers',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Assign a supplier to a product.
     */
    public function assignSupplier(Product $product, int $supplierId, array $data): void
    {
        $product->suppliers()->syncWithoutDetaching([
            $supplierId => [
                'supplier_cost' => $data['supplier_cost'] ?? 0,
                'lead_time_days' => $data['lead_time_days'] ?? null,
                'minimum_order_quantity' => $data['minimum_order_quantity'] ?? null,
                'is_preferred' => $data['is_preferred'] ?? false,
            ],
        ]);
    }

    /**
     * Add vehicle compatibility to a product.
     */
    public function addCompatibility(Product $product, int $engineId, ?string $notes = null): void
    {
        $product->compatibleEngines()->syncWithoutDetaching([
            $engineId => ['notes' => $notes],
        ]);
    }

    /**
     * Generate a unique 8-digit product system ID using atomic sequence.
     */
    private function generateProductSystemId(): string
    {
        $row = DB::table('system_sequences')
            ->where('key', 'product_system_id')
            ->lockForUpdate()
            ->first();

        if (! $row) {
            // Fallback: create if missing
            $nextValue = Product::count() + 1;
            DB::table('system_sequences')->insert([
                'key' => 'product_system_id',
                'next_value' => $nextValue + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return str_pad($nextValue, 8, '0', STR_PAD_LEFT);
        }

        $nextValue = $row->next_value;
        DB::table('system_sequences')
            ->where('key', 'product_system_id')
            ->update(['next_value' => $nextValue + 1, 'updated_at' => now()]);

        return str_pad($nextValue, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Generate a unique 8-digit variant system ID using a global atomic sequence.
     */
    private function generateVariantSystemId(): string
    {
        $row = DB::table('system_sequences')
            ->where('key', 'variant_system_id')
            ->lockForUpdate()
            ->first();

        if (! $row) {
            // Fallback: initialize from the current max numeric variant system_id
            $maxNumericId = DB::table('product_variants')
                ->whereNotNull('system_id')
                ->pluck('system_id')
                ->filter(fn ($id) => preg_match('/^\d{8}$/', (string) $id))
                ->map(fn ($id) => (int) $id)
                ->max() ?? 0;

            $nextValue = $maxNumericId + 1;

            DB::table('system_sequences')->insert([
                'key' => 'variant_system_id',
                'next_value' => $nextValue + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return str_pad($nextValue, 8, '0', STR_PAD_LEFT);
        }

        $nextValue = (int) $row->next_value;
        DB::table('system_sequences')
            ->where('key', 'variant_system_id')
            ->update(['next_value' => $nextValue + 1, 'updated_at' => now()]);

        return str_pad($nextValue, 8, '0', STR_PAD_LEFT);
    }
}
