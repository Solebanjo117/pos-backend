<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates product with multi-select attribute values', function () {
    $category = Category::create([
        'name' => 'Pintura',
        'default_attributes' => [
            [
                'name' => 'Acabado',
                'type' => 'select',
                'options' => ['Mate', 'Brillante', 'Satinado', 'Metalizado'],
            ],
        ],
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/products', [
        'name' => 'Pintura Multi',
        'category_id' => $category->id,
        'status' => 'active',
        'attributes' => [
            [
                'name' => 'Acabado',
                'value' => ['Mate', 'Brillante'],
                'source' => 'category',
                'source_category_id' => $category->id,
            ],
        ],
    ]);

    $response->assertCreated();

    $attrs = $response->json('data.attributes');
    $acabado = collect($attrs)->firstWhere('name', 'Acabado');

    expect($acabado)->not->toBeNull()
        ->and($acabado['value'])->toBe(['Mate', 'Brillante'])
        ->and($acabado['type'])->toBe('select');
});

it('rejects multi-select attribute with an invalid option', function () {
    $category = Category::create([
        'name' => 'Pintura',
        'default_attributes' => [
            [
                'name' => 'Acabado',
                'type' => 'select',
                'options' => ['Mate', 'Brillante', 'Satinado'],
            ],
        ],
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/products', [
        'name' => 'Pintura Invalida',
        'category_id' => $category->id,
        'status' => 'active',
        'attributes' => [
            [
                'name' => 'Acabado',
                'value' => ['Mate', 'NoExiste'],
                'source' => 'category',
                'source_category_id' => $category->id,
            ],
        ],
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('NoExiste');
});

it('updates product with multi-select attribute values', function () {
    $category = Category::create([
        'name' => 'Sensores',
        'default_attributes' => [
            [
                'name' => 'Compatibilidad',
                'type' => 'select',
                'options' => ['OBD1', 'OBD2', 'CAN-BUS'],
            ],
        ],
    ]);

    $product = Product::create([
        'sku_base' => 'MULTI-UPD-001',
        'name' => 'Sensor Multi',
        'category_id' => $category->id,
        'status' => 'active',
    ]);

    ProductAttribute::create([
        'product_id' => $product->id,
        'name' => 'Compatibilidad',
        'value' => json_encode(['OBD2']),
        'source' => 'category',
        'source_category_id' => $category->id,
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->putJson("/api/v1/products/{$product->id}", [
        'attributes' => [
            [
                'name' => 'Compatibilidad',
                'value' => ['OBD2', 'CAN-BUS'],
                'source' => 'category',
                'source_category_id' => $category->id,
            ],
        ],
    ]);

    $response->assertSuccessful();

    $attrs = $response->json('data.attributes');
    $compat = collect($attrs)->firstWhere('name', 'Compatibilidad');

    expect($compat['value'])->toBe(['OBD2', 'CAN-BUS']);
});

it('update rejects multi-select with invalid option', function () {
    $category = Category::create([
        'name' => 'Sensores',
        'default_attributes' => [
            [
                'name' => 'Protocolo',
                'type' => 'select',
                'options' => ['OBD1', 'OBD2', 'CAN-BUS'],
            ],
        ],
    ]);

    $product = Product::create([
        'sku_base' => 'MULTI-UPD-002',
        'name' => 'Sensor Invalido',
        'category_id' => $category->id,
        'status' => 'active',
    ]);

    ProductAttribute::create([
        'product_id' => $product->id,
        'name' => 'Protocolo',
        'value' => json_encode(['OBD2']),
        'source' => 'category',
        'source_category_id' => $category->id,
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->putJson("/api/v1/products/{$product->id}", [
        'attributes' => [
            [
                'name' => 'Protocolo',
                'value' => ['OBD2', 'BLUETOOTH'],
                'source' => 'category',
                'source_category_id' => $category->id,
            ],
        ],
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('BLUETOOTH');
});

it('single string value still works for select attributes (backward compat)', function () {
    $category = Category::create([
        'name' => 'Frenos',
        'default_attributes' => [
            [
                'name' => 'Tipo',
                'type' => 'select',
                'options' => ['Disco', 'Tambor', 'Ceramico'],
            ],
        ],
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/products', [
        'name' => 'Freno Disco Legacy',
        'category_id' => $category->id,
        'status' => 'active',
        'attributes' => [
            [
                'name' => 'Tipo',
                'value' => 'Disco',
                'source' => 'category',
                'source_category_id' => $category->id,
            ],
        ],
    ]);

    $response->assertCreated();

    $attrs = $response->json('data.attributes');
    $tipo = collect($attrs)->firstWhere('name', 'Tipo');

    // Even a single string value should be returned as array for select type
    expect($tipo['value'])->toBe(['Disco']);
});

it('legacy plain string value in DB is deserialized as array for select type', function () {
    $category = Category::create([
        'name' => 'Embrague',
        'default_attributes' => [
            [
                'name' => 'Material',
                'type' => 'select',
                'options' => ['Organico', 'Ceramico', 'Kevlar'],
            ],
        ],
    ]);

    $product = Product::create([
        'sku_base' => 'LEGACY-001',
        'name' => 'Embrague Legacy',
        'category_id' => $category->id,
        'status' => 'active',
    ]);

    // Simulate legacy data: plain string, not JSON
    ProductAttribute::create([
        'product_id' => $product->id,
        'name' => 'Material',
        'value' => 'Organico',
        'source' => 'category',
        'source_category_id' => $category->id,
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->getJson("/api/v1/products/{$product->id}");

    $response->assertSuccessful();

    $attrs = $response->json('data.attributes');
    $material = collect($attrs)->firstWhere('name', 'Material');

    // Legacy string should be wrapped in an array
    expect($material['value'])->toBe(['Organico']);
});

it('string type attribute value remains a plain string', function () {
    $category = Category::create([
        'name' => 'Herramientas',
        'default_attributes' => [
            [
                'name' => 'Peso',
                'type' => 'string',
            ],
        ],
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/products', [
        'name' => 'Herramienta String',
        'category_id' => $category->id,
        'status' => 'active',
        'attributes' => [
            [
                'name' => 'Peso',
                'value' => '2.5kg',
                'source' => 'category',
                'source_category_id' => $category->id,
            ],
        ],
    ]);

    $response->assertCreated();

    $attrs = $response->json('data.attributes');
    $peso = collect($attrs)->firstWhere('name', 'Peso');

    expect($peso['value'])->toBe('2.5kg')
        ->and($peso['type'])->toBe('string');
});

it('stores multi-select JSON correctly in the database', function () {
    $category = Category::create([
        'name' => 'Electricos',
        'default_attributes' => [
            [
                'name' => 'Voltaje',
                'type' => 'select',
                'options' => ['12V', '24V', '48V'],
            ],
        ],
    ]);

    $service = app(ProductService::class);

    $product = $service->createProduct([
        'name' => 'Componente Electrico',
        'category_id' => $category->id,
        'status' => 'active',
        'attributes' => [
            [
                'name' => 'Voltaje',
                'value' => ['12V', '24V'],
                'source' => 'category',
                'source_category_id' => $category->id,
            ],
        ],
    ]);

    $dbAttr = ProductAttribute::where('product_id', $product->id)
        ->where('name', 'Voltaje')
        ->first();

    // DB should store as JSON string
    expect($dbAttr->value)->toBe('["12V","24V"]');
});
