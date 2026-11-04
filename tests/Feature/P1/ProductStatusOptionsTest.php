<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $warehouse = Warehouse::create([
        'code' => 'W-STATUS',
        'name' => 'Almacén estados',
    ]);

    $user = User::factory()->create([
        'role' => 'buyer',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);

    $user->warehouses()->attach($warehouse->id);
    $this->actingAs($user);
});

it('only accepts active or discontinued when creating a product', function () {
    $this->postJson('/api/v1/products', ['name' => 'Producto pendiente', 'status' => 'pending'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status' => 'El estado debe ser: activo o discontinuado']);

    $this->postJson('/api/v1/products', ['name' => 'Producto discontinuado', 'status' => 'discontinued'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'discontinued');
});

it('only accepts active or discontinued when updating a product', function () {
    $product = Product::create(['sku_base' => 'STATUS-001', 'name' => 'Producto', 'status' => 'active']);

    $this->putJson('/api/v1/products/'.$product->id, ['status' => 'pending'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status' => 'El estado debe ser: activo o discontinuado']);

    $this->putJson('/api/v1/products/'.$product->id, ['status' => 'discontinued'])
        ->assertOk();

    expect($product->fresh()->status)->toBe('discontinued');
});

it('only accepts active or discontinued for product variants', function () {
    $product = Product::create(['sku_base' => 'STATUS-002', 'name' => 'Producto con variantes', 'status' => 'active']);
    $brand = Brand::create(['name' => 'Marca estados', 'status' => 'active']);

    $this->postJson("/api/v1/products/{$product->id}/variants", ['brand_id' => $brand->id, 'status' => 'pending'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'STATUS-002-V',
        'status' => 'active',
    ]);

    $this->putJson("/api/v1/products/{$product->id}/variants/{$variant->id}", ['brand_id' => $brand->id, 'status' => 'pending'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});
