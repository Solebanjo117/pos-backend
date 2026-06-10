<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $warehouse = Warehouse::create([
        'code' => 'W-PROD',
        'name' => 'Warehouse Products',
    ]);

    $user = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($warehouse->id);

    $this->actingAs($user);
});

it('returns products list', function () {
    $this->getJson('/api/v1/products')->assertOk();
});

it('accepts search by name', function () {
    $matchingProduct = Product::create([
        'sku_base' => 'NAME-SEARCH-001',
        'name' => 'Amortiguador Honda Civic',
        'status' => 'active',
    ]);

    Product::create([
        'sku_base' => 'NAME-SEARCH-002',
        'name' => 'Bomba de freno Toyota',
        'status' => 'active',
    ]);

    $response = $this->getJson('/api/v1/products?search=Honda');

    $response->assertOk();

    $returnedIds = collect($response->json('data'))->pluck('id')->all();
    expect($returnedIds)->toContain($matchingProduct->id);
});

it('matches product and variant codes while ignoring sku dashes', function (string $search) {
    $brand = Brand::create([
        'name' => '555',
        'status' => 'active',
    ]);

    $matchingProduct = Product::create([
        'sku_base' => '53010-S04-000',
        'alternate_code' => 'ALT-53010-S04-000',
        'name' => 'Base motor Honda Civic',
        'status' => 'active',
        'system_id' => 'SYS-53010-S04-000',
    ]);

    $matchingProduct->variants()->create([
        'brand_id' => $brand->id,
        'sku' => '50820-S04-013',
        'upc' => 'UPC-53010-S04-000',
        'status' => 'active',
        'system_id' => 'VAR-53010-S04-000',
        'is_default' => true,
    ]);

    $matchingProduct->oemReferences()->create([
        'oem_code' => 'OEM-53010-S04-000',
        'manufacturer' => 'Honda',
    ]);

    Product::create([
        'sku_base' => '99999-X99-999',
        'name' => 'Producto no relacionado',
        'status' => 'active',
    ]);

    $response = $this->getJson('/api/v1/products?'.http_build_query(['search' => $search]));

    $response->assertOk();

    $returnedIds = collect($response->json('data'))->pluck('id')->all();
    expect($returnedIds)->toContain($matchingProduct->id)
        ->and($returnedIds)->toHaveCount(1);
})->with([
    'short code token' => 'S04',
    'canonical code' => '53010-S04-000',
    'no dashes' => '53010S04000',
    'merged final segment' => '53010-S04000',
    'merged first segment' => '53010S04-000',
    'alternate code' => 'ALT53010S04000',
    'product system id' => 'SYS53010S04000',
    'variant sku' => '50820S04013',
    'variant upc' => 'UPC53010S04000',
    'variant system id' => 'VAR53010S04000',
    'oem code' => 'OEM53010S04000',
]);

it('keeps matching related text columns when searching products', function () {
    $brand = Brand::create([
        'name' => 'Kashima',
        'status' => 'active',
    ]);

    $category = Category::create([
        'name' => 'Freno Cilindro Rueda',
    ]);

    $matchingProduct = Product::create([
        'sku_base' => 'REL-SEARCH-001',
        'name' => 'Cilindro de rueda',
        'category_id' => $category->id,
        'status' => 'active',
    ]);

    $matchingProduct->variants()->create([
        'brand_id' => $brand->id,
        'sku' => 'REL-VAR-001',
        'status' => 'active',
    ]);

    $matchingProduct->attributes()->create([
        'name' => 'Lado',
        'value' => 'Derecho',
        'unit' => null,
    ]);

    Product::create([
        'sku_base' => 'REL-SEARCH-002',
        'name' => 'Producto no relacionado',
        'status' => 'active',
    ]);

    foreach (['Kashima', 'Freno', 'Derecho'] as $search) {
        $response = $this->getJson('/api/v1/products?'.http_build_query(['search' => $search]));

        $response->assertOk();

        $returnedIds = collect($response->json('data'))->pluck('id')->all();
        expect($returnedIds)->toContain($matchingProduct->id)
            ->and($returnedIds)->toHaveCount(1);
    }
});
