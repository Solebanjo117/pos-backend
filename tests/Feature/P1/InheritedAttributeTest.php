<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('attribute isInherited returns true for category source', function () {
    $category = Category::create(['name' => 'Filtros']);

    $product = Product::create([
        'sku_base' => 'INH-001',
        'name' => 'Filtro Aceite',
        'category_id' => $category->id,
        'status' => 'active',
    ]);

    $attr = ProductAttribute::create([
        'product_id' => $product->id,
        'name' => 'Material',
        'value' => 'Papel',
        'source' => 'category',
        'source_category_id' => $category->id,
    ]);

    expect($attr->isInherited())->toBeTrue();
    expect($attr->source_category_id)->toBe($category->id);
});

it('attribute isInherited returns false for custom source', function () {
    $product = Product::create([
        'sku_base' => 'CUS-001',
        'name' => 'Producto Custom',
        'status' => 'active',
    ]);

    $attr = ProductAttribute::create([
        'product_id' => $product->id,
        'name' => 'Peso',
        'value' => '2kg',
        'source' => 'custom',
    ]);

    expect($attr->isInherited())->toBeFalse();
});

it('updating inherited attribute value succeeds', function () {
    $category = Category::create(['name' => 'Aceites']);

    $product = Product::create([
        'sku_base' => 'UPD-INH-001',
        'name' => 'Aceite Motor',
        'category_id' => $category->id,
        'status' => 'active',
    ]);

    $attr = ProductAttribute::create([
        'product_id' => $product->id,
        'name' => 'Viscosidad',
        'value' => '5W-30',
        'source' => 'category',
        'source_category_id' => $category->id,
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->putJson("/api/v1/product-attributes/{$attr->id}", [
        'value' => '10W-40',
    ]);

    $response->assertSuccessful();
    expect($attr->fresh()->value)->toBe('10W-40');
});

it('creating attribute with source and category succeeds', function () {
    $category = Category::create(['name' => 'Bujias']);

    $product = Product::create([
        'sku_base' => 'SRC-001',
        'name' => 'Bujia Premium',
        'category_id' => $category->id,
        'status' => 'active',
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/product-attributes', [
        'product_id' => $product->id,
        'name' => 'Gap',
        'value' => '0.8mm',
        'source' => 'category',
        'source_category_id' => $category->id,
    ]);

    $response->assertCreated();
    expect($response->json('data.source'))->toBe('category');
    expect($response->json('data.source_category_id'))->toBe($category->id);
});

it('product update keeps omitted inherited attributes when categories do not change', function () {
    $category = Category::create(['name' => 'Baterias']);

    $product = Product::create([
        'sku_base' => 'KEEP-INH-001',
        'name' => 'Bateria AGM',
        'category_id' => $category->id,
        'status' => 'active',
    ]);

    ProductAttribute::create([
        'product_id' => $product->id,
        'name' => 'Voltaje',
        'value' => '12V',
        'source' => 'category',
        'source_category_id' => $category->id,
    ]);

    app(ProductService::class)->updateProduct($product, [
        'attributes' => [
            [
                'name' => 'Garantia',
                'value' => '12 meses',
                'source' => 'custom',
            ],
        ],
    ]);

    expect($product->fresh()->attributes()->where('name', 'Voltaje')->exists())->toBeTrue();
    expect($product->fresh()->attributes()->where('name', 'Garantia')->exists())->toBeTrue();
});
