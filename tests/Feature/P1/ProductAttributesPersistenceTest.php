<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $warehouse = Warehouse::create([
        'code' => 'W-ATTR',
        'name' => 'Warehouse Attributes',
    ]);

    $user = User::factory()->create([
        'role' => 'buyer',
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

it('persists product attributes with category source metadata', function () {
    expect(Schema::hasColumns('product_attributes', ['source', 'source_category_id']))->toBeTrue();

    $category = Category::create([
        'name' => 'Categoria Atributos Fuente',
        'slug' => 'categoria-atributos-fuente-'.uniqid(),
    ]);

    $response = $this->postJson('/api/v1/products', [
        'name' => 'Producto con atributo heredado',
        'status' => 'active',
        'category_id' => $category->id,
        'attributes' => [
            [
                'name' => 'Largo',
                'value' => '10',
                'unit' => 'mm',
                'source' => 'category',
                'source_category_id' => $category->id,
            ],
            [
                'name' => 'Nota interna',
                'value' => 'Uso taller',
                'source' => 'custom',
                'source_category_id' => $category->id,
            ],
        ],
    ]);

    $response->assertStatus(201);

    $productId = $response->json('data.id');

    $this->assertDatabaseHas('product_attributes', [
        'product_id' => $productId,
        'variant_id' => null,
        'name' => 'Largo',
        'value' => '10',
        'unit' => 'mm',
        'source' => 'category',
        'source_category_id' => $category->id,
    ]);

    $this->assertDatabaseHas('product_attributes', [
        'product_id' => $productId,
        'variant_id' => null,
        'name' => 'Nota interna',
        'value' => 'Uso taller',
        'source' => 'custom',
        'source_category_id' => null,
    ]);
});

it('updates product-level attributes without deleting legacy variant-scoped rows', function () {
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

it('keeps created product attributes at product level only', function () {
    $brand = Brand::create([
        'name' => 'Marca Producto Atributos',
        'status' => 'active',
    ]);

    $response = $this->postJson('/api/v1/products', [
        'name' => 'Alternador con atributo base',
        'status' => 'active',
        'brand_id' => $brand->id,
        'sku' => 'ATTR-BASE-VAR-001',
        'attributes' => [
            [
                'name' => 'Amperaje',
                'value' => '140',
                'unit' => 'A',
            ],
        ],
    ]);

    $response->assertStatus(201);

    $productId = $response->json('data.id');
    $variantId = ProductVariant::where('product_id', $productId)->value('id');

    expect($variantId)->not->toBeNull();

    $this->assertDatabaseHas('product_attributes', [
        'product_id' => $productId,
        'variant_id' => null,
        'name' => 'Amperaje',
        'value' => '140',
        'unit' => 'A',
    ]);

    $this->assertDatabaseMissing('product_attributes', [
        'product_id' => $productId,
        'variant_id' => $variantId,
        'name' => 'Amperaje',
    ]);
});

it('rejects variant-scoped attribute writes', function () {
    $product = Product::create([
        'sku_base' => 'ATTR-SCOPE-001',
        'name' => 'Producto alcance atributos',
        'status' => 'active',
    ]);

    $brand = Brand::create([
        'name' => 'Marca Alcance Atributos',
        'status' => 'active',
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'ATTR-SCOPE-VAR-001',
        'status' => 'active',
        'is_default' => true,
    ]);

    $this->postJson('/api/v1/product-attributes', [
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'name' => 'Lado',
        'value' => 'Derecho',
    ])->assertStatus(422)
        ->assertJsonValidationErrors('variant_id');

    $this->getJson("/api/v1/products/{$product->id}/attributes?variant_id={$variant->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors('variant_id');

    $this->postJson("/api/v1/products/{$product->id}/variants", [
        'brand_id' => $brand->id,
        'sku' => 'ATTR-SCOPE-VAR-002',
        'attributes' => [
            [
                'name' => 'Lado',
                'value' => 'Izquierdo',
            ],
        ],
    ])->assertStatus(422)
        ->assertJsonValidationErrors('attributes');

    $this->putJson("/api/v1/products/{$product->id}/variants/{$variant->id}", [
        'attributes' => [
            [
                'name' => 'Lado',
                'value' => 'Derecho',
            ],
        ],
    ])->assertStatus(422)
        ->assertJsonValidationErrors('attributes');

    $this->assertDatabaseMissing('product_attributes', [
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'name' => 'Lado',
    ]);
});
