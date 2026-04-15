<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\OemReference;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductInventory;
use App\Models\ProductLine;
use App\Models\ProductMedia;
use App\Models\ProductPricing;
use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use App\Models\ProductVehicleCompatibility;
use App\Models\ProductVin;
use App\Models\Supplier;
use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->warehouse = Warehouse::create([
        'code' => 'W-DUP',
        'name' => 'Warehouse Duplicate',
    ]);

    $user = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $this->warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($this->warehouse->id);

    $this->actingAs($user);
});

function createSourceProductForDuplication(Warehouse $warehouse): array
{
    $brandA = Brand::create(['name' => 'Brand Dup A ' . uniqid(), 'status' => 'active']);
    $brandB = Brand::create(['name' => 'Brand Dup B ' . uniqid(), 'status' => 'active']);

    $line = ProductLine::create([
        'brand_id' => $brandA->id,
        'name' => 'Linea Dup',
        'priority' => 1,
    ]);

    $category = Category::create([
        'name' => 'Categoria Dup',
        'slug' => 'categoria-dup-' . uniqid(),
        'priority' => 1,
    ]);

    $supplierA = Supplier::create([
        'name' => 'Supplier Dup A ' . uniqid(),
        'status' => 'active',
    ]);

    $supplierB = Supplier::create([
        'name' => 'Supplier Dup B ' . uniqid(),
        'status' => 'active',
    ]);

    $make = VehicleMake::create(['name' => 'Make Dup ' . uniqid()]);
    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Model Dup',
        'year_from' => 2020,
        'year_to' => 2025,
    ]);
    $engine = VehicleEngine::create([
        'model_id' => $model->id,
        'engine_code' => 'ENG-DUP',
        'displacement' => '2.0L',
    ]);

    $source = Product::create([
        'sku_base' => 'DUP-BASE-001',
        'name' => 'Terminal Derecho',
        'description' => 'Producto fuente para duplicacion',
        'status' => 'active',
        'category_id' => $category->id,
        'product_line_id' => $line->id,
        'pricing_formula' => 'costo * 1.5',
        'alternate_code' => 'ALT-001',
        'is_universal' => false,
    ]);

    $variantA = ProductVariant::create([
        'product_id' => $source->id,
        'brand_id' => $brandA->id,
        'sku' => 'DUP-VAR-A',
        'upc' => 'UPC-DUP-A',
        'status' => 'active',
        'is_default' => true,
        'system_id' => '90000001',
    ]);

    $variantB = ProductVariant::create([
        'product_id' => $source->id,
        'brand_id' => $brandB->id,
        'sku' => 'DUP-VAR-B',
        'upc' => 'UPC-DUP-B',
        'status' => 'active',
        'is_default' => false,
        'system_id' => '90000002',
    ]);

    ProductPricing::create([
        'product_id' => $source->id,
        'variant_id' => null,
        'base_price' => 100,
        'msrp' => 130,
        'cost' => 60,
        'margin_percentage' => 40,
        'tax_rate' => 7,
        'tax_type' => 'standard',
        'max_discount_percentage' => 10,
        'pricing_formula' => 'costo * 1.5',
    ]);

    ProductPricing::create([
        'product_id' => $source->id,
        'variant_id' => $variantA->id,
        'base_price' => 110,
        'msrp' => 140,
        'cost' => 65,
        'margin_percentage' => 40.91,
        'tax_rate' => 7,
        'tax_type' => 'standard',
        'max_discount_percentage' => 10,
        'pricing_formula' => 'costo * 1.5',
    ]);

    ProductPricing::create([
        'product_id' => $source->id,
        'variant_id' => $variantB->id,
        'base_price' => 120,
        'msrp' => 150,
        'cost' => 70,
        'margin_percentage' => 41.67,
        'tax_rate' => 7,
        'tax_type' => 'standard',
        'max_discount_percentage' => 10,
        'pricing_formula' => 'costo * 1.5',
    ]);

    ProductAttribute::create([
        'product_id' => $source->id,
        'name' => 'Material',
        'value' => 'Acero',
        'unit' => null,
    ]);

    ProductAttribute::create([
        'product_id' => $source->id,
        'variant_id' => $variantA->id,
        'name' => 'Lado',
        'value' => 'Derecho',
        'unit' => null,
    ]);

    ProductAttribute::create([
        'product_id' => $source->id,
        'variant_id' => $variantB->id,
        'name' => 'Lado',
        'value' => 'Izquierdo',
        'unit' => null,
    ]);

    $variantA->suppliers()->sync([
        $supplierA->id => [
            'supplier_cost' => 31.50,
            'lead_time_days' => 4,
            'minimum_order_quantity' => 2,
            'is_preferred' => true,
        ],
    ]);

    $variantB->suppliers()->sync([
        $supplierB->id => [
            'supplier_cost' => 29.00,
            'lead_time_days' => 6,
            'minimum_order_quantity' => 1,
            'is_preferred' => false,
        ],
    ]);

    ProductSupplier::create([
        'product_id' => $source->id,
        'supplier_id' => $supplierA->id,
        'supplier_cost' => 30.00,
        'lead_time_days' => 5,
        'minimum_order_quantity' => 1,
        'is_preferred' => true,
    ]);

    ProductVehicleCompatibility::create([
        'product_id' => $source->id,
        'engine_id' => $engine->id,
        'notes' => 'Compatibilidad base',
        'all_engines' => false,
        'all_chassis' => false,
    ]);

    OemReference::create([
        'product_id' => $source->id,
        'oem_code' => 'OEM-DUP-001',
        'manufacturer' => 'OEM Co',
    ]);

    ProductMedia::create([
        'product_id' => $source->id,
        'media_type' => 'image',
        'category' => 'photo',
        'file_path' => 'products/dup-test.jpg',
        'original_filename' => 'dup-test.jpg',
        'file_size' => 1024,
        'display_order' => 0,
    ]);

    ProductInventory::create([
        'product_id' => $source->id,
        'variant_id' => $variantA->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 10,
        'quantity_reserved' => 2,
        'reorder_point' => 3,
        'reorder_quantity' => 8,
    ]);

    ProductVin::create([
        'product_id' => $source->id,
        'vin' => '1HGCM82633A004352',
        'notes' => 'VIN fuente',
    ]);

    $otherProduct = Product::create([
        'sku_base' => 'DUP-ALT-001',
        'name' => 'Alternativo',
        'status' => 'active',
        'category_id' => $category->id,
    ]);

    $source->alternatives()->attach($otherProduct->id, [
        'relationship_type' => 'compatible',
    ]);

    return [
        'source' => $source,
        'variant_a' => $variantA,
        'variant_b' => $variantB,
    ];
}

it('duplicates product preserving core setup and excluding inventory, vins and alternatives', function () {
    $data = createSourceProductForDuplication($this->warehouse);
    $source = $data['source'];

    $response = $this->postJson("/api/v1/products/{$source->id}/duplicate");

    $response
        ->assertStatus(201)
        ->assertJsonPath('message', 'Producto duplicado. Ajusta los campos necesarios.');

    $duplicatedId = $response->json('data.id');

    expect($duplicatedId)->not->toBeNull();
    expect($duplicatedId)->not->toBe($source->id);

    $duplicated = Product::with([
        'pricing',
        'attributes',
        'productSuppliers',
        'vehicleCompatibility',
        'oemReferences',
        'media',
        'variants.suppliers',
        'variants.pricing',
        'variants.attributes',
        'vins',
        'inventory',
        'alternatives',
    ])->findOrFail($duplicatedId);

    expect($duplicated->name)->toBe('Terminal Derecho (Copia)');
    expect($duplicated->sku_base)->not->toBe($source->sku_base);
    expect($duplicated->pricing?->base_price)->toBe('100.00');

    expect($duplicated->variants)->toHaveCount(2);

    $duplicatedByBrand = $duplicated->variants->keyBy('brand_id');
    expect($duplicatedByBrand[$data['variant_a']->brand_id]->sku)->toBe($data['variant_a']->sku);
    expect($duplicatedByBrand[$data['variant_b']->brand_id]->sku)->toBe($data['variant_b']->sku);
    expect($duplicatedByBrand[$data['variant_a']->brand_id]->system_id)->not->toBe($data['variant_a']->system_id);
    expect($duplicatedByBrand[$data['variant_b']->brand_id]->system_id)->not->toBe($data['variant_b']->system_id);

    expect($duplicatedByBrand[$data['variant_a']->brand_id]->suppliers)->toHaveCount(1);
    expect($duplicatedByBrand[$data['variant_b']->brand_id]->suppliers)->toHaveCount(1);

    expect($duplicated->productSuppliers)->toHaveCount(1);
    expect($duplicated->vehicleCompatibility)->toHaveCount(1);
    expect($duplicated->oemReferences)->toHaveCount(1);
    expect($duplicated->media)->toHaveCount(1);

    expect($duplicated->inventory)->toHaveCount(0);
    expect($duplicated->vins)->toHaveCount(0);
    expect($duplicated->alternatives)->toHaveCount(0);

    $this->assertDatabaseCount('product_inventory', 1);
    $this->assertDatabaseCount('product_vins', 1);
});

it('returns 404 when duplicating a non-existent product', function () {
    $this->postJson('/api/v1/products/999999/duplicate')->assertStatus(404);
});
