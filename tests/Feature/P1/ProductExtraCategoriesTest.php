<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $warehouse = Warehouse::create([
        'code' => 'W-EXTRA-CAT',
        'name' => 'Warehouse Extra Categories',
    ]);

    $user = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);

    $user->warehouses()->attach($warehouse->id);
    $this->actingAs($user);
});

it('creates products with extra categories for name and inherited attributes', function () {
    $mainCategory = Category::create([
        'name' => 'Terminales',
        'slug' => 'terminales-'.uniqid(),
        'priority' => 1,
        'default_attributes' => [
            ['name' => 'Material', 'value' => 'Principal'],
            ['name' => 'Largo', 'value' => '10', 'unit' => 'cm'],
        ],
    ]);

    $extraCategoryB = Category::create([
        'name' => 'Bujes',
        'slug' => 'bujes-'.uniqid(),
        'priority' => 2,
        'default_attributes' => [
            ['name' => 'Material', 'value' => 'Extra'],
            ['name' => 'Diametro', 'value' => '12', 'unit' => 'mm'],
        ],
    ]);

    $extraCategoryA = Category::create([
        'name' => 'Alternadores',
        'slug' => 'alternadores-'.uniqid(),
        'priority' => 3,
        'default_attributes' => [
            ['name' => 'Voltaje', 'value' => '12', 'unit' => 'V'],
        ],
    ]);

    $response = $this->postJson('/api/v1/products', [
        'category_id' => $mainCategory->id,
        'extra_category_ids' => [$extraCategoryB->id, $extraCategoryA->id, $mainCategory->id],
        'status' => 'active',
    ]);

    $response
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'Alternadores Bujes Terminales');

    $productId = $response->json('data.id');
    expect(collect($response->json('data.extra_category_ids'))->sort()->values()->all())
        ->toBe(collect([$extraCategoryA->id, $extraCategoryB->id])->sort()->values()->all());

    $this->assertDatabaseHas('product_extra_categories', [
        'product_id' => $productId,
        'category_id' => $extraCategoryA->id,
    ]);
    $this->assertDatabaseHas('product_extra_categories', [
        'product_id' => $productId,
        'category_id' => $extraCategoryB->id,
    ]);
    $this->assertDatabaseMissing('product_extra_categories', [
        'product_id' => $productId,
        'category_id' => $mainCategory->id,
    ]);

    $this->assertDatabaseHas('product_attributes', [
        'product_id' => $productId,
        'variant_id' => null,
        'name' => 'Material',
        'value' => 'Principal',
    ]);
    $this->assertDatabaseMissing('product_attributes', [
        'product_id' => $productId,
        'variant_id' => null,
        'name' => 'Material',
        'value' => 'Extra',
    ]);
    $this->assertDatabaseHas('product_attributes', [
        'product_id' => $productId,
        'variant_id' => null,
        'name' => 'Diametro',
        'value' => '12',
        'unit' => 'mm',
    ]);
    $this->assertDatabaseHas('product_attributes', [
        'product_id' => $productId,
        'variant_id' => null,
        'name' => 'Voltaje',
        'value' => '12',
        'unit' => 'V',
    ]);
});

it('updates extra categories while preserving existing attribute values and custom attributes', function () {
    $oldMain = Category::create([
        'name' => 'Filtros',
        'slug' => 'filtros-'.uniqid(),
        'priority' => 1,
    ]);
    $newMain = Category::create([
        'name' => 'Bombas',
        'slug' => 'bombas-'.uniqid(),
        'priority' => 2,
        'default_attributes' => [
            ['name' => 'Material', 'value' => 'Nuevo default'],
        ],
    ]);
    $oldExtra = Category::create([
        'name' => 'Aceites',
        'slug' => 'aceites-'.uniqid(),
        'priority' => 3,
    ]);
    $newExtra = Category::create([
        'name' => 'Motores',
        'slug' => 'motores-'.uniqid(),
        'priority' => 4,
        'default_attributes' => [
            ['name' => 'Amperaje', 'value' => '80', 'unit' => 'A'],
        ],
    ]);

    $product = Product::create([
        'sku_base' => 'EXTRA-UPD-001',
        'name' => 'Producto con extras',
        'status' => 'active',
        'category_id' => $oldMain->id,
    ]);
    $product->extraCategories()->sync([$oldExtra->id]);

    ProductAttribute::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'Material',
        'value' => 'Manual',
    ]);
    ProductAttribute::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'Color',
        'value' => 'Rojo',
    ]);

    $this->putJson("/api/v1/products/{$product->id}", [
        'category_id' => $newMain->id,
        'extra_category_ids' => [$newExtra->id],
    ])->assertStatus(200);

    $this->assertDatabaseMissing('product_extra_categories', [
        'product_id' => $product->id,
        'category_id' => $oldExtra->id,
    ]);
    $this->assertDatabaseHas('product_extra_categories', [
        'product_id' => $product->id,
        'category_id' => $newExtra->id,
    ]);
    $this->assertDatabaseHas('product_attributes', [
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'Material',
        'value' => 'Manual',
    ]);
    $this->assertDatabaseHas('product_attributes', [
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'Amperaje',
        'value' => '80',
        'unit' => 'A',
    ]);
    $this->assertDatabaseHas('product_attributes', [
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'Color',
        'value' => 'Rojo',
    ]);
});

it('filters products by main or extra category without duplicates', function () {
    $mainCategory = Category::create([
        'name' => 'Radiadores',
        'slug' => 'radiadores-'.uniqid(),
        'priority' => 1,
    ]);
    $extraCategory = Category::create([
        'name' => 'Enfriamiento',
        'slug' => 'enfriamiento-'.uniqid(),
        'priority' => 2,
    ]);
    $otherCategory = Category::create([
        'name' => 'Frenos',
        'slug' => 'frenos-'.uniqid(),
        'priority' => 3,
    ]);

    $product = Product::create([
        'sku_base' => 'EXTRA-FILTER-001',
        'name' => 'Radiador con categoria extra',
        'status' => 'active',
        'category_id' => $mainCategory->id,
    ]);
    $product->extraCategories()->sync([$extraCategory->id]);

    Product::create([
        'sku_base' => 'EXTRA-FILTER-002',
        'name' => 'Producto fuera de filtro',
        'status' => 'active',
        'category_id' => $otherCategory->id,
    ]);

    $extraResponse = $this->getJson("/api/v1/products?category_id={$extraCategory->id}");
    $extraResponse->assertStatus(200);
    expect(collect($extraResponse->json('data'))->pluck('id')->all())->toContain($product->id);

    $combinedResponse = $this->getJson("/api/v1/products?category_id[]={$mainCategory->id}&category_id[]={$extraCategory->id}");
    $combinedResponse->assertStatus(200);
    expect(collect($combinedResponse->json('data'))->where('id', $product->id)->count())->toBe(1);
});

it('regenerates name with submitted extra categories and applies their defaults', function () {
    $mainCategory = Category::create([
        'name' => 'Motor',
        'slug' => 'motor-'.uniqid(),
        'priority' => 1,
        'default_attributes' => [
            ['name' => 'Material', 'value' => 'Acero'],
        ],
    ]);
    $extraCategory = Category::create([
        'name' => 'Anillos',
        'slug' => 'anillos-'.uniqid(),
        'priority' => 2,
        'default_attributes' => [
            ['name' => 'Medida', 'value' => 'STD'],
        ],
    ]);

    $product = Product::create([
        'sku_base' => 'EXTRA-REGEN-PENDING-001',
        'name' => 'Nombre manual',
        'status' => 'active',
        'category_id' => $mainCategory->id,
    ]);

    ProductAttribute::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'Material',
        'value' => 'Manual',
    ]);

    $this->postJson("/api/v1/products/{$product->id}/regenerate-name", [
        'category_id' => $mainCategory->id,
        'extra_category_ids' => [$extraCategory->id],
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Anillos Motor')
        ->assertJsonPath('data.extra_category_ids.0', $extraCategory->id);

    $this->assertDatabaseHas('product_extra_categories', [
        'product_id' => $product->id,
        'category_id' => $extraCategory->id,
    ]);
    $this->assertDatabaseHas('product_attributes', [
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'Material',
        'value' => 'Manual',
    ]);
    $this->assertDatabaseHas('product_attributes', [
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'Medida',
        'value' => 'STD',
    ]);
});

it('regenerates names and duplicates products with extra categories', function () {
    $mainCategory = Category::create([
        'name' => 'Motor',
        'slug' => 'motor-'.uniqid(),
        'priority' => 1,
    ]);
    $extraCategory = Category::create([
        'name' => 'Anillos',
        'slug' => 'anillos-'.uniqid(),
        'priority' => 2,
    ]);

    $product = Product::create([
        'sku_base' => 'EXTRA-REGEN-001',
        'name' => 'Nombre manual',
        'status' => 'active',
        'category_id' => $mainCategory->id,
    ]);
    $product->extraCategories()->sync([$extraCategory->id]);

    $this->postJson("/api/v1/products/{$product->id}/regenerate-name")
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Anillos Motor');

    $duplicateResponse = $this->postJson("/api/v1/products/{$product->id}/duplicate");
    $duplicateResponse->assertStatus(201);

    $this->assertDatabaseHas('product_extra_categories', [
        'product_id' => $duplicateResponse->json('data.id'),
        'category_id' => $extraCategory->id,
    ]);
});
