<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $warehouse = Warehouse::create([
        'code' => 'W-DEFERRED',
        'name' => 'Warehouse Deferred Metadata',
    ]);

    $user = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);

    $user->warehouses()->attach($warehouse->id);
    $this->actingAs($user);
});

function createDeferredMetadataEngine(string $makeName, string $modelName, string $engineCode): VehicleEngine
{
    $make = VehicleMake::create([
        'name' => $makeName,
    ]);

    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => $modelName,
        'year_from' => 2000,
        'year_to' => 2008,
    ]);

    return VehicleEngine::create([
        'model_id' => $model->id,
        'engine_code' => $engineCode,
        'displacement' => '1.5L',
        'fuel_type' => 'Gasolina',
    ]);
}

it('syncs deferred product metadata when the final update request is submitted', function () {
    $product = Product::create([
        'sku_base' => 'DEFERRED-001',
        'name' => 'Producto diferido',
        'status' => 'active',
    ]);

    $oldAlternative = Product::create([
        'sku_base' => 'DEFERRED-ALT-OLD',
        'name' => 'Alternativa vieja',
        'status' => 'active',
    ]);

    $newAlternative = Product::create([
        'sku_base' => 'DEFERRED-ALT-NEW',
        'name' => 'Alternativa nueva',
        'status' => 'active',
    ]);

    $oldEngine = createDeferredMetadataEngine('Toyota', 'Corolla', 'OLD-1NZ');
    $newEngine = createDeferredMetadataEngine('Honda', 'Civic', 'NEW-D17');

    ProductAttribute::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'Material',
        'value' => 'Acero',
    ]);

    $product->oemReferences()->create([
        'oem_code' => 'OEM-OLD',
        'manufacturer' => 'Marca vieja',
    ]);

    $product->alternatives()->attach($oldAlternative->id, [
        'relationship_type' => 'compatible',
    ]);

    $product->vehicleCompatibility()->create([
        'engine_id' => $oldEngine->id,
    ]);

    $this->putJson("/api/v1/products/{$product->id}", [
        'name' => 'Producto diferido actualizado',
        'attributes' => [
            [
                'name' => 'Voltaje',
                'value' => '12',
                'unit' => 'V',
            ],
        ],
        'oem_references' => [
            [
                'oem_code' => 'OEM-NEW',
                'manufacturer' => 'Marca nueva',
            ],
        ],
        'alternatives' => [
            [
                'alternative_product_id' => $newAlternative->id,
                'relationship_type' => 'upgrade',
            ],
        ],
        'vehicle_compatibility' => [
            'engines' => [
                ['engine_id' => $newEngine->id],
            ],
            'chassis' => [],
            'allEngines' => false,
            'allChassis' => false,
            'isUniversal' => false,
        ],
        'is_universal' => false,
    ])->assertStatus(200);

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'name' => 'Producto diferido actualizado',
    ]);
    $this->assertDatabaseMissing('product_attributes', [
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'Material',
        'value' => 'Acero',
    ]);
    $this->assertDatabaseHas('product_attributes', [
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'Voltaje',
        'value' => '12',
        'unit' => 'V',
    ]);
    $this->assertDatabaseMissing('oem_references', [
        'product_id' => $product->id,
        'oem_code' => 'OEM-OLD',
    ]);
    $this->assertDatabaseHas('oem_references', [
        'product_id' => $product->id,
        'oem_code' => 'OEM-NEW',
        'manufacturer' => 'Marca nueva',
    ]);
    $this->assertDatabaseMissing('alternative_products', [
        'primary_product_id' => $product->id,
        'alternative_product_id' => $oldAlternative->id,
    ]);
    $this->assertDatabaseHas('alternative_products', [
        'primary_product_id' => $product->id,
        'alternative_product_id' => $newAlternative->id,
        'relationship_type' => 'upgrade',
    ]);
    $this->assertDatabaseMissing('product_vehicle_compatibility', [
        'product_id' => $product->id,
        'engine_id' => $oldEngine->id,
    ]);
    $this->assertDatabaseHas('product_vehicle_compatibility', [
        'product_id' => $product->id,
        'engine_id' => $newEngine->id,
    ]);
});

it('does not delete omitted deferred metadata blocks on product update', function () {
    $product = Product::create([
        'sku_base' => 'DEFERRED-OMIT-001',
        'name' => 'Producto con bloques',
        'status' => 'active',
    ]);

    $alternative = Product::create([
        'sku_base' => 'DEFERRED-OMIT-ALT',
        'name' => 'Alternativa conservada',
        'status' => 'active',
    ]);

    $engine = createDeferredMetadataEngine('Nissan', 'Sentra', 'QR25');

    ProductAttribute::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'Color',
        'value' => 'Negro',
    ]);

    $product->oemReferences()->create([
        'oem_code' => 'OEM-KEEP',
        'manufacturer' => 'Marca conservada',
    ]);

    $product->alternatives()->attach($alternative->id, [
        'relationship_type' => 'substitute',
    ]);

    $product->vehicleCompatibility()->create([
        'engine_id' => $engine->id,
    ]);

    $this->putJson("/api/v1/products/{$product->id}", [
        'name' => 'Producto con bloques editado',
    ])->assertStatus(200);

    $this->assertDatabaseHas('product_attributes', [
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'Color',
        'value' => 'Negro',
    ]);
    $this->assertDatabaseHas('oem_references', [
        'product_id' => $product->id,
        'oem_code' => 'OEM-KEEP',
    ]);
    $this->assertDatabaseHas('alternative_products', [
        'primary_product_id' => $product->id,
        'alternative_product_id' => $alternative->id,
        'relationship_type' => 'substitute',
    ]);
    $this->assertDatabaseHas('product_vehicle_compatibility', [
        'product_id' => $product->id,
        'engine_id' => $engine->id,
    ]);
});

it('previews generated product name without persisting product metadata', function () {
    $mainCategory = Category::create([
        'name' => 'Motor',
        'slug' => 'motor-preview-'.uniqid(),
        'priority' => 1,
    ]);

    $extraCategory = Category::create([
        'name' => 'Anillos',
        'slug' => 'anillos-preview-'.uniqid(),
        'priority' => 2,
    ]);

    $product = Product::create([
        'sku_base' => 'DEFERRED-PREVIEW-001',
        'name' => 'Nombre persistido',
        'status' => 'active',
        'category_id' => $mainCategory->id,
    ]);

    $engine = createDeferredMetadataEngine('Toyota', 'Corolla', '1NZ');

    $this->postJson("/api/v1/products/{$product->id}/preview-name", [
        'category_id' => $mainCategory->id,
        'extra_category_ids' => [$extraCategory->id],
        'vehicle_compatibility' => [
            'engines' => [
                ['engine_id' => $engine->id],
            ],
            'chassis' => [],
            'allEngines' => false,
            'allChassis' => false,
            'isUniversal' => false,
        ],
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Anillos Motor - 1NZ Toyota Corolla 00-08');

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'name' => 'Nombre persistido',
        'category_id' => $mainCategory->id,
    ]);
    $this->assertDatabaseMissing('product_extra_categories', [
        'product_id' => $product->id,
        'category_id' => $extraCategory->id,
    ]);
    $this->assertDatabaseMissing('product_vehicle_compatibility', [
        'product_id' => $product->id,
        'engine_id' => $engine->id,
    ]);
});
