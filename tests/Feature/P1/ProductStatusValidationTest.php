<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createProductWithStatus(string $status, string $name = 'Test Product'): Product
{
    $product = Product::create([
        'sku_base' => 'TEST-'.uniqid(),
        'name' => $name,
        'status' => $status,
    ]);

    $brand = Brand::create(['name' => 'Test Brand '.$product->id, 'status' => 'active']);

    ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'TV-'.$product->id,
        'status' => $status,
        'is_default' => true,
    ]);

    return $product;
}

it('discontinued product is not vendible', function () {
    $product = createProductWithStatus('discontinued', 'Producto Descontinuado');

    expect($product->isVendible())->toBeFalse();
});

it('pending product is not vendible', function () {
    $product = createProductWithStatus('pending', 'Producto Pendiente');

    expect($product->isVendible())->toBeFalse();
});

it('active product is vendible', function () {
    $product = createProductWithStatus('active', 'Producto Activo');

    expect($product->isVendible())->toBeTrue();
});

it('scope vendible returns only active products', function () {
    createProductWithStatus('active', 'Activo');
    createProductWithStatus('discontinued', 'Descontinuado');
    createProductWithStatus('pending', 'Pendiente');

    $vendibleProducts = Product::vendible()->get();

    expect($vendibleProducts)->toHaveCount(1);
    expect($vendibleProducts->first()->name)->toBe('Activo');
});

it('product attribute source defaults to custom', function () {
    $product = Product::create([
        'sku_base' => 'ATTR-TEST-001',
        'name' => 'Attribute Test Product',
        'status' => 'active',
    ]);

    $attr = ProductAttribute::create([
        'product_id' => $product->id,
        'name' => 'Color',
        'value' => 'Rojo',
    ]);

    expect($attr->source)->toBe('custom');
    expect($attr->source_category_id)->toBeNull();
});

it('product attribute can be inherited from category', function () {
    $category = Category::create([
        'name' => 'Baterias',
        'default_attributes' => [
            ['name' => 'Voltaje', 'value' => '12V', 'unit' => 'V'],
        ],
    ]);

    $product = Product::create([
        'sku_base' => 'INH-TEST-001',
        'name' => 'Inherited Attr Product',
        'category_id' => $category->id,
        'status' => 'active',
    ]);

    $attr = ProductAttribute::create([
        'product_id' => $product->id,
        'name' => 'Voltaje',
        'value' => '12V',
        'unit' => 'V',
        'source' => 'category',
        'source_category_id' => $category->id,
    ]);

    expect($attr->isInherited())->toBeTrue();
    expect($attr->source)->toBe('category');
    expect($attr->source_category_id)->toBe($category->id);
});

it('delete inherited attribute returns 409', function () {
    $product = Product::create([
        'sku_base' => 'DEL-TEST-001',
        'name' => 'Delete Test Product',
        'status' => 'active',
    ]);

    $attr = ProductAttribute::create([
        'product_id' => $product->id,
        'name' => 'Amperaje',
        'value' => '90A',
        'source' => 'category',
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->deleteJson("/api/v1/product-attributes/{$attr->id}");

    $response->assertStatus(409);
    $response->assertJsonPath('message', fn ($msg) => str_contains($msg, 'heredado'));
});

it('delete custom attribute succeeds', function () {
    $product = Product::create([
        'sku_base' => 'DEL-CUST-001',
        'name' => 'Delete Custom Product',
        'status' => 'active',
    ]);

    $attr = ProductAttribute::create([
        'product_id' => $product->id,
        'name' => 'Peso',
        'value' => '5kg',
        'source' => 'custom',
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->deleteJson("/api/v1/product-attributes/{$attr->id}");

    $response->assertSuccessful();
});

it('archived product is not vendible', function () {
    $product = createProductWithStatus('active', 'Producto a Archivar');
    $product->delete();

    $fresh = Product::withTrashed()->find($product->id);

    expect($fresh->trashed())->toBeTrue();
    expect($fresh->isVendible())->toBeFalse();
});

it('restored product retains its status', function () {
    $product = createProductWithStatus('active', 'Producto Restaurado');
    $product->delete();
    $product->restore();

    $fresh = Product::find($product->id);

    expect($fresh->status)->toBe('active');
    expect($fresh->isVendible())->toBeTrue();
});
