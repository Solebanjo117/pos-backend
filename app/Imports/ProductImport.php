<?php

namespace App\Imports;

use App\Models\Brand;
use App\Models\Category;
use App\Services\ProductService;
use Illuminate\Http\UploadedFile;

class ProductImport extends BaseImport
{
    public function __construct(private readonly ProductService $productService) {}

    public function getColumns(): array
    {
        return [
            ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text', 'required' => true],
            ['key' => 'sku', 'label' => 'SKU', 'type' => 'text'],
            ['key' => 'descripcion', 'label' => 'Descripción', 'type' => 'text'],
            ['key' => 'categoria', 'label' => 'Categoría', 'type' => 'searchable', 'required' => true, 'endpoint' => '/categories/all'],
        ];
    }

    public function getExampleRow(): array
    {
        return [
            'nombre' => 'Bujía Iridium',
            'sku' => 'SKU-001',
            'descripcion' => 'Bujía para motor gasolina',
            'categoria' => 'Encendido',
        ];
    }

    public function getEntityLabel(): string
    {
        return 'producto';
    }

    public function preview(UploadedFile $file, int $maxRows = 200): array
    {
        return parent::preview($file, $maxRows);
    }

    public function execute(array $rows): void
    {
        parent::execute($rows);
    }

    protected function mapHeader(string $header): string
    {
        return match ($header) {
            'nombre', 'name' => 'nombre',
            'sku' => 'sku',
            'descripcion', 'description' => 'descripcion',
            'categoria', 'category' => 'categoria',
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

        return $errors;
    }

    public function processRow(array $row): mixed
    {
        $categoryId = $this->resolveIdOrCreateByName(Category::class, 'name', $row['categoria']);

        $productData = [
            'name' => $row['nombre'],
            'sku_base' => $this->nullIfEmpty($row['sku'] ?? null),
            'description' => $this->nullIfEmpty($row['descripcion'] ?? null),
            'category_id' => $categoryId,
            'status' => 'active',
        ];

        // Atributos enriquecidos desde la UI
        $attributes = $row['__attributes'] ?? [];
        if (is_array($attributes) && ! empty($attributes)) {
            $productData['attributes'] = array_values(array_filter(
                $attributes,
                fn ($a) => ! empty($a['name']) && ! empty($a['value'])
            ));
        }

        // Compatibilidad vehicular enriquecida desde la UI
        $compatibility = $row['__compatibility'] ?? [];
        if (is_array($compatibility) && ! empty($compatibility)) {
            $productData['vehicle_compatibility'] = array_column($compatibility, 'engine_id');
        }

        $product = $this->productService->createProduct($productData);

        // Variantes enriquecidas desde la UI (draft variants del VariantManager)
        $variants = $row['__variants'] ?? [];
        if (is_array($variants) && ! empty($variants)) {
            foreach ($variants as $variantData) {
                $brandId = null;
                if (! empty($variantData['brand_id'])) {
                    $brandId = (int) $variantData['brand_id'];
                } elseif (! empty($variantData['brand']['name'])) {
                    $brandId = $this->resolveOrCreate(Brand::class, 'name', $variantData['brand']['name'], ['status' => 'active']);
                }

                if (! $brandId) {
                    continue;
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
        }

        return $product;
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
