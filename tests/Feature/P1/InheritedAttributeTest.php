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

it('category with attribute type string allows any text value', function () {
    $category = Category::create([
        'name' => 'Repuestos Generales',
        'default_attributes' => [
            [
                'name' => 'LENGTH',
                'type' => 'string',
                'required' => false,
            ],
        ],
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/products', [
        'name' => 'Producto Texto Libre',
        'category_id' => $category->id,
        'status' => 'active',
        'attributes' => [
            [
                'name' => 'LENGTH',
                'value' => 'Cualquier texto aquí',
                'source' => 'category',
                'source_category_id' => $category->id,
            ],
        ],
    ]);

    $response->assertCreated();
    expect($response->json('data.attributes'))->toContain([
        'name' => 'LENGTH',
        'value' => 'Cualquier texto aquí',
        'unit' => null,
        'type' => 'string',
        'options' => null,
        'source' => 'category',
        'source_category_id' => $category->id,
    ]);
});

it('category with select type attribute accepts valid option', function () {
    $category = Category::create([
        'name' => 'Suspension',
        'default_attributes' => [
            [
                'name' => 'POSICION',
                'type' => 'select',
                'options' => ['DELANTERO', 'TRASERO'],
                'required' => true,
            ],
        ],
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/products', [
        'name' => 'Amortiguador Delantero',
        'category_id' => $category->id,
        'status' => 'active',
        'attributes' => [
            [
                'name' => 'POSICION',
                'value' => 'DELANTERO',
                'source' => 'category',
                'source_category_id' => $category->id,
            ],
        ],
    ]);

    $response->assertCreated();
    expect($response->json('data.attributes'))->toContain([
        'name' => 'POSICION',
        'value' => 'DELANTERO',
        'unit' => null,
        'type' => 'select',
        'options' => ['DELANTERO', 'TRASERO'],
        'source' => 'category',
        'source_category_id' => $category->id,
    ]);
});

it('category with select type attribute rejects invalid option', function () {
    $category = Category::create([
        'name' => 'Suspension',
        'default_attributes' => [
            [
                'name' => 'POSICION',
                'type' => 'select',
                'options' => ['DELANTERO', 'TRASERO'],
                'required' => true,
            ],
        ],
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/products', [
        'name' => 'Amortiguador Invalido',
        'category_id' => $category->id,
        'status' => 'active',
        'attributes' => [
            [
                'name' => 'POSICION',
                'value' => 'LATERAL',
                'source' => 'category',
                'source_category_id' => $category->id,
            ],
        ],
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('no es válido');
});

it('product update rejects invalid select option value', function () {
    $category = Category::create([
        'name' => 'Espejos',
        'default_attributes' => [
            [
                'name' => 'LADO',
                'type' => 'select',
                'options' => ['RH', 'LH'],
                'required' => true,
            ],
        ],
    ]);

    $product = Product::create([
        'sku_base' => 'ESP-001',
        'name' => 'Espejo Retrovisor',
        'category_id' => $category->id,
        'status' => 'active',
    ]);

    ProductAttribute::create([
        'product_id' => $product->id,
        'name' => 'LADO',
        'value' => 'RH',
        'source' => 'category',
        'source_category_id' => $category->id,
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->putJson("/api/v1/products/{$product->id}", [
        'attributes' => [
            [
                'name' => 'LADO',
                'value' => 'CENTRO',
                'source' => 'category',
                'source_category_id' => $category->id,
            ],
        ],
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.attributes'))->toContain('El atributo "LADO" debe ser uno de: RH, LH');
});

it('standalone attribute update rejects invalid select option value', function () {
    $category = Category::create([
        'name' => 'Espejos',
        'default_attributes' => [
            [
                'name' => 'LADO',
                'type' => 'select',
                'options' => ['RH', 'LH'],
                'required' => true,
            ],
        ],
    ]);

    $product = Product::create([
        'sku_base' => 'ESP-DIRECT-001',
        'name' => 'Espejo directo',
        'category_id' => $category->id,
        'status' => 'active',
    ]);

    $attribute = ProductAttribute::create([
        'product_id' => $product->id,
        'name' => 'LADO',
        'value' => 'RH',
        'source' => 'category',
        'source_category_id' => $category->id,
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->putJson("/api/v1/product-attributes/{$attribute->id}", [
        'value' => 'CENTRO',
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.value'))->toContain('El atributo "LADO" debe ser uno de: RH, LH');
    expect($attribute->fresh()->value)->toBe('RH');
});

it('standalone attribute store rejects invalid select option value', function () {
    $category = Category::create([
        'name' => 'Suspension directa',
        'default_attributes' => [
            [
                'name' => 'POSICION',
                'type' => 'select',
                'options' => ['DELANTERO', 'TRASERO'],
                'required' => true,
            ],
        ],
    ]);

    $product = Product::create([
        'sku_base' => 'SUS-DIRECT-001',
        'name' => 'Amortiguador directo',
        'category_id' => $category->id,
        'status' => 'active',
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/product-attributes', [
        'product_id' => $product->id,
        'name' => 'POSICION',
        'value' => 'LATERAL',
        'source' => 'category',
        'source_category_id' => $category->id,
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.value'))->toContain('El atributo "POSICION" debe ser uno de: DELANTERO, TRASERO');
});

it('category attribute without explicit type defaults to string', function () {
    $category = Category::create([
        'name' => 'Herramientas',
        'default_attributes' => [
            [
                'name' => 'Material',
                'required' => false,
            ],
        ],
    ]);

    expect($category->default_attributes[0]['type'])->toBe('string');
});

it('category cannot be created with select attribute without options', function () {
    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/categories', [
        'name' => 'Categoria Invalida',
        'default_attributes' => [
            [
                'name' => 'Selector',
                'type' => 'select',
                'options' => [],
            ],
        ],
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('select debe tener al menos una opción válida');
});

it('changing categories recalculates inherited attributes and preserves values when name matches', function () {
    $categoryA = Category::create([
        'name' => 'Categoria A',
        'default_attributes' => [
            [
                'name' => 'COLOR',
                'type' => 'select',
                'options' => ['Rojo', 'Azul', 'Verde'],
            ],
        ],
    ]);

    $categoryB = Category::create([
        'name' => 'Categoria B',
        'default_attributes' => [
            [
                'name' => 'COLOR',
                'type' => 'select',
                'options' => ['Rojo', 'Negro', 'Blanco'],
            ],
        ],
    ]);

    $product = Product::create([
        'sku_base' => 'CHG-CAT-001',
        'name' => 'Producto Cambio Categoria',
        'category_id' => $categoryA->id,
        'status' => 'active',
    ]);

    ProductAttribute::create([
        'product_id' => $product->id,
        'name' => 'COLOR',
        'value' => 'Rojo',
        'source' => 'category',
        'source_category_id' => $categoryA->id,
    ]);

    app(ProductService::class)->updateProduct($product, [
        'category_id' => $categoryB->id,
        'attributes' => [
            [
                'name' => 'COLOR',
                'value' => 'Rojo',
                'source' => 'category',
                'source_category_id' => $categoryB->id,
            ],
        ],
    ]);

    $product->refresh();
    $colorAttr = $product->attributes()->where('name', 'COLOR')->first();

    expect($colorAttr->value)->toBe('Rojo');
    expect($colorAttr->source_category_id)->toBe($categoryB->id);
});
