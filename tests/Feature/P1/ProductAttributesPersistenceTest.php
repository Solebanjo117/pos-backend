<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $warehouse = Warehouse::create([
        'code' => 'W-ATTR',
        'name' => 'Warehouse Attributes',
    ]);

    $user = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);

    $user->warehouses()->attach($warehouse->id);
    $this->actingAs($user);
});

it('persists product-level attributes when creating a product without default variant', function () {
    $response = $this->postJson('/api/v1/products', [
        'name' => 'Filtro de prueba',
        'status' => 'active',
        'attributes' => [
            [
                'name' => 'Amperaje',
                'value' => '100',
                'unit' => 'A',
            ],
        ],
    ]);

    $response->assertStatus(201);

    $productId = $response->json('data.id');
    expect($productId)->not->toBeNull();

    $this->assertDatabaseHas('product_attributes', [
        'product_id' => $productId,
        'variant_id' => null,
        'name' => 'Amperaje',
        'value' => '100',
        'unit' => 'A',
    ]);
});

it('updates only product-level attributes without deleting variant-level attributes', function () {
    $product = Product::create([
        'sku_base' => 'ATTR-BASE-001',
        'name' => 'Bomba de prueba',
        'status' => 'active',
    ]);

    $brand = Brand::create([
        'name' => 'Marca Atributos',
        'status' => 'active',
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'ATTR-VAR-001',
        'status' => 'active',
        'is_default' => true,
    ]);

    ProductAttribute::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'Material',
        'value' => 'Acero',
        'unit' => null,
    ]);

    ProductAttribute::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'name' => 'Lado',
        'value' => 'Derecho',
        'unit' => null,
    ]);

    $response = $this->putJson("/api/v1/products/{$product->id}", [
        'attributes' => [
            [
                'name' => 'Amperaje',
                'value' => '120',
                'unit' => 'A',
            ],
        ],
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('product_attributes', [
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'Amperaje',
        'value' => '120',
        'unit' => 'A',
    ]);

    $this->assertDatabaseMissing('product_attributes', [
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'Material',
        'value' => 'Acero',
    ]);

    $this->assertDatabaseHas('product_attributes', [
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'name' => 'Lado',
        'value' => 'Derecho',
    ]);
});
