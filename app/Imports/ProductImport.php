<?php

namespace App\Imports;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\VehicleChassis;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\ProductService;

class ProductImport extends BaseImport
{
    public function __construct(private readonly ProductService $productService) {}

    public function getColumns(): array
    {
        return [
            ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text', 'required' => true],
            ['key' => 'sku', 'label' => 'SKU', 'type' => 'text'],
            ['key' => 'descripcion', 'label' => 'Descripcion', 'type' => 'text'],
            ['key' => 'categoria', 'label' => 'Categoria', 'type' => 'searchable', 'required' => true, 'endpoint' => '/categories/all'],
            ['key' => 'categorias_extra', 'label' => 'Categorias Extra', 'type' => 'text'],
            ['key' => 'atributos', 'label' => 'Atributos', 'type' => 'text'],
            ['key' => 'make', 'label' => 'Marca Vehiculo', 'type' => 'text'],
            ['key' => 'model', 'label' => 'Modelo Vehiculo', 'type' => 'text'],
            ['key' => 'year', 'label' => 'Ano Vehiculo', 'type' => 'text'],
            ['key' => 'code', 'label' => 'Chasis/Codigo', 'type' => 'text'],
            ['key' => 'engine', 'label' => 'Motor', 'type' => 'text'],
            ['key' => 'vin', 'label' => 'VIN', 'type' => 'text'],
            ['key' => 'upsale_path', 'label' => 'Path Upsale', 'type' => 'text'],
        ];
    }

    public function getExampleRow(): array
    {
        return [
            'nombre' => 'Bujia Iridium',
            'sku' => 'SKU-001',
            'descripcion' => 'Bujia para motor gasolina',
            'categoria' => 'Encendido',
            'categorias_extra' => 'Motor, Servicio',
            'atributos' => 'color:rojo,largo:15cm',
            'make' => 'TOYOTA',
            'model' => 'YARIS',
            'year' => '2006-2013',
            'code' => 'P90',
            'engine' => '1NZFE',
            'vin' => 'JTDBT933X01109408',
            'upsale_path' => 'motor/encendido',
        ];
    }

    public function getEntityLabel(): string
    {
        return 'producto';
    }

    protected function mapHeader(string $header): string
    {
        return match ($header) {
            'nombre', 'name' => 'nombre',
            'sku', 'sku_base' => 'sku',
            'descripcion', 'description' => 'descripcion',
            'categoria', 'category' => 'categoria',
            'categorias_extra', 'extra_categories', 'extra_category_ids' => 'categorias_extra',
            'atributos', 'attributes' => 'atributos',
            'make', 'marca', 'vehicle_make' => 'make',
            'model', 'modelo', 'vehicle_model' => 'model',
            'year', 'ano', 'years', 'year_range' => 'year',
            'code', 'codigo', 'chassis', 'chassis_code' => 'code',
            'engine', 'motor', 'engine_code' => 'engine',
            'vin', 'numero_vin', 'vin_number' => 'vin',
            'upsale_path', 'path_upsale', 'categoria_upsale', 'category_upsale' => 'upsale_path',
            default => $header,
        };
    }

    public function validateRow(array $row): array
    {
        $errors = [];

        if (trim((string) ($row['nombre'] ?? '')) === '') {
            $errors[] = 'El campo nombre es obligatorio.';
        }

        if (trim((string) ($row['categoria'] ?? '')) === '') {
            $errors[] = 'El campo categoria es obligatorio.';
        }

        try {
            $this->parseProductAttributes($row['atributos'] ?? null);
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        foreach ($this->splitDelimitedValues($row['vin'] ?? null) as $vin) {
            if (mb_strlen($vin) > 40) {
                $errors[] = "El VIN '{$vin}' no puede tener mas de 40 caracteres.";
            }
        }

        return $errors;
    }

    public function processRow(array $row): mixed
    {
        $categoryId = $this->resolveIdOrCreateByName(Category::class, 'name', $row['categoria']);
        $upsalePath = $this->nullIfEmpty($row['upsale_path'] ?? null);

        if ($upsalePath !== null) {
            Category::query()->whereKey($categoryId)->update(['upsale_path' => $upsalePath]);
        }

        $productData = [
            'name' => $row['nombre'],
            'sku_base' => $this->nullIfEmpty($row['sku'] ?? null),
            'description' => $this->nullIfEmpty($row['descripcion'] ?? null),
            'category_id' => $categoryId,
            'status' => 'active',
        ];

        $extraCategoryIds = $this->resolveExtraCategoryIds($row['categorias_extra'] ?? null, $categoryId);
        if (! empty($extraCategoryIds)) {
            $productData['extra_category_ids'] = $extraCategoryIds;
        }

        $attributes = $this->resolveAttributes($row);
        if (! empty($attributes)) {
            $productData['attributes'] = $attributes;
        }

        $compatibility = $this->resolveVehicleCompatibility($row);
        if ($compatibility !== []) {
            $productData['vehicle_compatibility'] = $compatibility;
        }

        $vins = $this->splitDelimitedValues($row['vin'] ?? null);
        if ($vins !== []) {
            $productData['vins'] = array_map(fn (string $vin): string => strtoupper(trim($vin)), $vins);
        }

        $product = $this->productService->createProduct($productData);

        $variants = $row['__variants'] ?? [];
        if (is_array($variants) && ! empty($variants)) {
            foreach ($variants as $variantData) {
                $this->createEnrichedVariant($product, $variantData);
            }
        }

        return $product;
    }

    private function resolveVehicleCompatibility(array $row): array
    {
        $makeName = $this->nullIfEmpty($row['make'] ?? null);
        if ($makeName === null) {
            return [];
        }

        $makeId = $this->resolveIdOrCreateByName(VehicleMake::class, 'name', $makeName);
        $compatibility = [
            'makes' => [['make_id' => $makeId]],
        ];

        $modelName = $this->nullIfEmpty($row['model'] ?? null);
        if ($modelName === null) {
            return $compatibility;
        }

        [$yearFrom, $yearTo] = $this->parseYearRange($row['year'] ?? null);
        $generation = $this->nullIfEmpty($row['code'] ?? null);
        $model = VehicleModel::query()
            ->where('make_id', $makeId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($modelName)])
            ->when($generation, fn ($query) => $query->whereRaw('LOWER(generation) = ?', [mb_strtolower($generation)]))
            ->when(! $generation && $yearFrom !== null, fn ($query) => $query->where('year_from', $yearFrom)->where('year_to', $yearTo ?? $yearFrom))
            ->first();

        if (! $model) {
            $model = VehicleModel::create(array_filter([
                'make_id' => $makeId,
                'name' => $modelName,
                'year_from' => $yearFrom,
                'year_to' => $yearTo ?? $yearFrom,
                'generation' => $generation,
            ], fn ($value) => $value !== null));
        }

        $compatibility['models'] = [['model_id' => $model->id]];

        $chassisCode = $this->nullIfEmpty($row['code'] ?? null);
        if ($chassisCode !== null) {
            $chassis = VehicleChassis::query()
                ->where(function ($query) use ($model) {
                    $query->where('model_id', $model->id)
                        ->orWhereHas('models', fn ($modelsQuery) => $modelsQuery->where('vehicle_models.id', $model->id));
                })
                ->whereRaw('LOWER(code) = ?', [mb_strtolower($chassisCode)])
                ->first();

            if (! $chassis) {
                $chassis = VehicleChassis::create(array_filter([
                    'code' => strtoupper(trim($chassisCode)),
                    'name' => strtoupper(trim($chassisCode)),
                    'model_id' => $model->id,
                    'year_from' => $yearFrom,
                    'year_to' => $yearTo ?? $yearFrom,
                ], fn ($value) => $value !== null));
            }

            $model->chassis()->syncWithoutDetaching([$chassis->id]);
            $compatibility['chassis'] = [['chassis_id' => $chassis->id]];
        }

        $engineCode = $this->nullIfEmpty($row['engine'] ?? null);
        if ($engineCode !== null) {
            $engine = VehicleEngine::query()
                ->where(function ($query) use ($model) {
                    $query->where('model_id', $model->id)
                        ->orWhereHas('models', fn ($modelsQuery) => $modelsQuery->where('vehicle_models.id', $model->id));
                })
                ->whereRaw('LOWER(engine_code) = ?', [mb_strtolower($engineCode)])
                ->first();

            if (! $engine) {
                $engine = VehicleEngine::create([
                    'model_id' => $model->id,
                    'engine_code' => strtoupper(trim($engineCode)),
                ]);
            }

            $engine->models()->syncWithoutDetaching([$model->id]);
            $compatibility['engines'] = [['engine_id' => $engine->id]];
        }

        return $compatibility;
    }

    private function parseYearRange(mixed $yearValue): array
    {
        $yearStr = trim((string) ($yearValue ?? ''));
        if ($yearStr === '') {
            return [null, null];
        }

        if (preg_match('/^(\d{4})\s*(?:-|\x{2013})\s*(\d{4})$/u', $yearStr, $matches) === 1) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        if (preg_match('/^(\d{4})$/', $yearStr, $matches) === 1) {
            $year = (int) $matches[1];

            return [$year, $year];
        }

        return [null, null];
    }

    private function resolveExtraCategoryIds(mixed $value, int $primaryCategoryId): array
    {
        return collect($this->splitDelimitedValues($value))
            ->map(fn ($categoryName) => $this->resolveIdOrCreateByName(Category::class, 'name', $categoryName))
            ->reject(fn (int $categoryId) => $categoryId === $primaryCategoryId)
            ->unique()
            ->values()
            ->all();
    }

    private function resolveAttributes(array $row): array
    {
        $uiAttributes = $row['__attributes'] ?? [];
        if (is_array($uiAttributes) && ! empty($uiAttributes)) {
            return array_values(array_filter(
                $uiAttributes,
                fn ($attribute) => ! empty($attribute['name']) && ! empty($attribute['value'])
            ));
        }

        return $this->parseProductAttributes($row['atributos'] ?? null);
    }

    private function createEnrichedVariant(Product $product, array $variantData): void
    {
        $brandId = null;
        if (! empty($variantData['brand_id'])) {
            $brandId = (int) $variantData['brand_id'];
        } elseif (! empty($variantData['brand']['name'])) {
            $brandId = $this->resolveOrCreate(Brand::class, 'name', $variantData['brand']['name'], ['status' => 'active']);
        }

        if (! $brandId) {
            return;
        }

        $variant = $this->productService->createVariant($product, [
            'brand_id' => $brandId,
            'sku' => $this->nullIfEmpty($variantData['sku'] ?? null) ?? ($product->sku_base ?? ''),
            'status' => 'active',
            'is_default' => false,
        ]);

        if (! empty($variantData['pricing']) && $variant) {
            $pricing = $variant->pricing()->create($this->buildVariantPricingPayload(
                $product->id,
                $variant->id,
                $variantData['pricing']
            ));
            $pricing->calculateMargin();
            $pricing->save();
        }

        $initialInventory = $variantData['initial_inventory'] ?? [];
        if (is_array($initialInventory) && ! empty($initialInventory)) {
            foreach ($initialInventory as $inventoryData) {
                if (empty($inventoryData['warehouse_id'])) {
                    continue;
                }

                $variant->inventory()->create([
                    'product_id' => $product->id,
                    'warehouse_id' => (int) $inventoryData['warehouse_id'],
                    'quantity_available' => (int) ($inventoryData['stock_available'] ?? 0),
                    'reorder_point' => (int) ($inventoryData['reorder_point'] ?? 0),
                    'reorder_quantity' => (int) ($inventoryData['reorder_quantity'] ?? 0),
                ]);
            }
        }
    }

    private function buildVariantPricingPayload(int $productId, int $variantId, array $pricingData): array
    {
        $saleTaxRate = (int) ($pricingData['sale_tax_rate'] ?? $pricingData['tax_rate'] ?? 7);
        $costTaxRate = (int) ($pricingData['cost_tax_rate'] ?? $saleTaxRate);
        $costBase = $this->parseMoney($pricingData['cost_base'] ?? null);
        $costMiscellaneous = $this->parseMoney($pricingData['cost_miscellaneous'] ?? null) ?? 0;
        $legacyCost = $this->parseMoney($pricingData['cost'] ?? null);

        $payload = [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'base_price' => (float) ($pricingData['base_price'] ?? 0),
            'msrp' => (float) ($pricingData['msrp'] ?? 0),
            'tax_rate' => $saleTaxRate,
            'sale_tax_rate' => $saleTaxRate,
            'cost_tax_rate' => $costTaxRate,
            'tax_type' => $pricingData['tax_type'] ?? 'standard',
            'max_discount_percentage' => (float) ($pricingData['max_discount_percentage'] ?? 0),
            'pricing_formula' => $this->nullIfEmpty($pricingData['pricing_formula'] ?? null),
        ];

        if ($costBase !== null) {
            $payload['cost_base'] = $costBase;
            $payload['cost_miscellaneous'] = $costMiscellaneous;
            $payload['cost'] = round($costBase * (1 + ($costTaxRate / 100)) + $costMiscellaneous, 2);
        } else {
            $payload['cost'] = $legacyCost ?? 0;
        }

        return $payload;
    }

    protected function getLabelColumn(): string
    {
        return 'nombre';
    }
}
