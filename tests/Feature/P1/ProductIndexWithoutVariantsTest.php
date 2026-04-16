<?php

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $warehouse = Warehouse::create([
        'code' => 'W-NOVAR',
        'name' => 'Warehouse No Variant',
    ]);

    $user = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);

    $user->warehouses()->attach($warehouse->id);
    $this->actingAs($user);
});

test('products index includes products that have no variants', function () {
    $productWithoutVariants = Product::create([
        'sku_base' => 'NOVAR-001',
        'name' => 'Producto sin variantes',
        'status' => 'active',
    ]);

    Product::create([
        'sku_base' => 'NOVAR-002',
        'name' => 'Otro producto',
        'status' => 'active',
    ]);

    $response = $this->getJson('/api/v1/products?per_page=50');

    $response->assertOk();

    $returnedIds = collect($response->json('data'))->pluck('id')->all();
    expect($returnedIds)->toContain($productWithoutVariants->id);
});

test('products index exposes key_attributes for products without variants', function () {
    $product = Product::create([
        'sku_base' => 'NOVAR-ATTR-001',
        'name' => 'Producto sin variantes con atributo',
        'status' => 'active',
    ]);

    ProductAttribute::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'Amperaje',
        'value' => '100',
        'unit' => 'A',
    ]);

    $response = $this->getJson('/api/v1/products?per_page=50');

    $response->assertOk();

    $resource = collect($response->json('data'))->firstWhere('id', $product->id);
    expect($resource)->not->toBeNull();
    expect(data_get($resource, 'key_attributes.0.name'))->toBe('Amperaje');
    expect(data_get($resource, 'key_attributes.0.value'))->toBe('100');
    expect(data_get($resource, 'key_attributes.0.unit'))->toBe('A');
});
