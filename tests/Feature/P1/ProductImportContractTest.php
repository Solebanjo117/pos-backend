<?php

use App\Imports\ProductImport;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPricing;
use App\Models\Warehouse;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('accepts searchable ids and persists enriched product-level import data', function () {
    $category = Category::create([
        'name' => 'Import Category',
        'slug' => 'import-category',
    ]);

    $brand = Brand::create([
        'name' => 'Import Brand',
        'status' => 'active',
    ]);

    $warehouse = Warehouse::create([
        'code' => 'W-IMP',
        'name' => 'Warehouse Import',
    ]);

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'Producto importado',
            'sku' => 'IMP-001',
            'categoria' => $category->id,
            '__attributes' => [
                [
                    'name' => 'Amperaje',
                    'value' => '90',
                    'unit' => 'A',
                ],
            ],
            '__variants' => [
                [
                    'brand_id' => $brand->id,
                    'sku' => 'IMP-001-BRD',
                    'pricing' => [
                        'base_price' => 125,
                        'msrp' => 140,
                        'cost_base' => 50,
                        'cost_tax_rate' => 7,
                        'cost_miscellaneous' => 3,
                        'sale_tax_rate' => 7,
                    ],
                    'initial_inventory' => [
                        [
                            'warehouse_id' => $warehouse->id,
                            'stock_available' => 4,
                            'reorder_point' => 2,
                            'reorder_quantity' => 8,
                        ],
                    ],
                ],
            ],
        ],
    ]);

    expect($import->successCount)->toBe(1)
        ->and($import->errorCount)->toBe(0);

    $product = Product::where('sku_base', 'IMP-001')->firstOrFail();
    $variant = $product->variants()->where('brand_id', $brand->id)->firstOrFail();

    $this->assertDatabaseHas('product_attributes', [
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'Amperaje',
        'value' => '90',
        'unit' => 'A',
    ]);

    $this->assertDatabaseMissing('product_attributes', [
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'name' => 'Amperaje',
    ]);

    $this->assertDatabaseHas('product_inventory', [
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 4,
        'reorder_point' => 2,
        'reorder_quantity' => 8,
    ]);

    $pricing = ProductPricing::where('variant_id', $variant->id)->firstOrFail();

    expect((float) $pricing->cost)->toBe(56.5)
        ->and((float) $pricing->cost_total)->toBe(56.5)
        ->and((float) $pricing->cost_base)->toBe(50.0)
        ->and((float) $pricing->cost_miscellaneous)->toBe(3.0);
});
