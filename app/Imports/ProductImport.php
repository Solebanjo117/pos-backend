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
            'sku'    => 'SKU-001',
            'descripcion' => 'Bujía para motor gasolina',
            'categoria'   => 'Encendido',
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
            'sku'            => 'sku',
            'descripcion', 'description' => 'descripcion',
            'categoria', 'category'      => 'categoria',
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
        $categoryId = $this->resolveOrCreate(Category::class, 'name', $row['categoria']);

        $productData = [
            'name'        => $row['nombre'],
            'sku_base'    => $this->nullIfEmpty($row['sku'] ?? null),
            'description' => $this->nullIfEmpty($row['descripcion'] ?? null),
            'category_id' => $categoryId,
            'status'      => 'active',
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
                    'brand_id'   => $brandId,
                    'sku'        => $this->nullIfEmpty($variantData['sku'] ?? null) ?? ($product->sku_base ?? ''),
                    'status'     => 'active',
                    'is_default' => false,
                ]);

                if (! empty($variantData['pricing']) && $variant) {
                    $pricing = $variant->pricing()->create([
                        'product_id'             => $product->id,
                        'base_price'             => (float) ($variantData['pricing']['base_price'] ?? 0),
                        'msrp'                   => (float) ($variantData['pricing']['msrp'] ?? 0),
                        'cost'                   => (float) ($variantData['pricing']['cost'] ?? 0),
                        'tax_rate'               => (int) ($variantData['pricing']['tax_rate'] ?? 7),
                        'sale_tax_rate'          => (int) ($variantData['pricing']['sale_tax_rate'] ?? 7),
                        'cost_tax_rate'          => (int) ($variantData['pricing']['cost_tax_rate'] ?? 7),
                        'tax_type'               => $variantData['pricing']['tax_type'] ?? 'standard',
                        'max_discount_percentage' => 0,
                    ]);
                    $pricing->calculateMargin();
                    $pricing->save();
                }
            }
        }

        return $product;
    }

    protected function getLabelColumn(): string
    {
        return 'nombre';
    }
}
