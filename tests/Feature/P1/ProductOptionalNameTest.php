<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $warehouse = Warehouse::create([
        'code' => 'W-NAME',
        'name' => 'Almacén descripción',
    ]);

    $user = User::factory()->create([
        'role' => 'buyer',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);

    $user->warehouses()->attach($warehouse->id);
    $this->actingAs($user);
});

it('creates a product without a description and generates it from the category', function () {
    $category = Category::create(['name' => 'Filtros']);

    $this->postJson('/api/v1/products', ['name' => '', 'category_id' => $category->id])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Filtros');
});

it('updates a product with a blank description and regenerates it instead of failing', function (mixed $blank) {
    $category = Category::create(['name' => 'Bujías']);
    $product = Product::create([
        'sku_base' => 'NAME-001',
        'name' => 'Nombre viejo',
        'status' => 'active',
        'category_id' => $category->id,
    ]);

    $this->putJson('/api/v1/products/'.$product->id, ['name' => $blank, 'status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Bujías');

    expect($product->fresh()->name)->toBe('Bujías');
})->with([
    'empty string' => [''],
    'spaces' => ['   '],
    'null' => [null],
]);

it('keeps a typed description when updating', function () {
    $product = Product::create(['sku_base' => 'NAME-002', 'name' => 'Viejo', 'status' => 'active']);

    $this->putJson('/api/v1/products/'.$product->id, ['name' => '  Filtro especial  '])
        ->assertOk();

    expect($product->fresh()->name)->toBe('Filtro especial');
});

it('leaves the description untouched when the update does not send it', function () {
    $product = Product::create(['sku_base' => 'NAME-003', 'name' => 'Sin cambio', 'status' => 'active']);

    $this->putJson('/api/v1/products/'.$product->id, ['status' => 'discontinued'])
        ->assertOk();

    expect($product->fresh()->name)->toBe('Sin cambio');
});
