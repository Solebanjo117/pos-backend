<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('user from warehouse A does not see stock from warehouse B', function () {
    $w1 = Warehouse::create(['code' => 'W1', 'name' => 'Warehouse 1']);
    $w2 = Warehouse::create(['code' => 'W2', 'name' => 'Warehouse 2']);

    $seller = User::factory()->create([
        'role' => 'seller',
        'is_active' => true,
        'current_warehouse_id' => $w1->id,
    ]);
    $seller->warehouses()->attach($w1->id);

    $brand = Brand::create(['name' => 'Brand A', 'status' => 'active']);
    $product = Product::create(['sku_base' => 'SKU-ISO-1', 'name' => 'Producto ISO', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'SKU-ISO-1-V1',
        'status' => 'active',
        'is_default' => true,
    ]);

    ProductInventory::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $w1->id,
        'quantity_available' => 3,
        'quantity_reserved' => 0,
    ]);

    ProductInventory::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $w2->id,
        'quantity_available' => 99,
        'quantity_reserved' => 0,
    ]);

    $response = $this->actingAs($seller)
        ->getJson('/api/v1/products?warehouse_id=' . $w2->id . '&per_page=10')
        ->assertStatus(200);

    expect($response->json('data.0.total_stock'))->toBe(3);
});
