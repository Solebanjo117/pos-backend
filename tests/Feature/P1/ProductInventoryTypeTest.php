<?php

use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $warehouse = Warehouse::create([
        'code' => 'W-INVTYPE',
        'name' => 'Almacén tipo de inventario',
    ]);

    $user = User::factory()->create([
        'role' => 'buyer',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);

    $user->warehouses()->attach($warehouse->id);
    $this->actingAs($user);
});

it('defaults every product to the single inventory type', function () {
    $product = Product::create(['sku_base' => 'INVTYPE-001', 'name' => 'Filtro', 'status' => 'active']);

    expect($product->fresh()->inventory_type)->toBe('single');

    $this->getJson('/api/v1/products/'.$product->id)
        ->assertOk()
        ->assertJsonPath('data.inventory_type', 'single');
});

it('changes the inventory type of a product from its edit form', function () {
    $product = Product::create(['sku_base' => 'INVTYPE-002', 'name' => 'Mano de obra', 'status' => 'active']);

    $this->putJson('/api/v1/products/'.$product->id, ['inventory_type' => 'non_inventory'])
        ->assertOk();

    expect($product->fresh()->inventory_type)->toBe('non_inventory');

    $this->getJson('/api/v1/products?search=INVTYPE-002')
        ->assertOk()
        ->assertJsonPath('data.0.inventory_type', 'non_inventory');

    $this->putJson('/api/v1/products/'.$product->id, ['name' => 'Mano de obra general'])
        ->assertOk();

    expect($product->fresh()->inventory_type)->toBe('non_inventory');
});

it('only accepts single or non-inventory types', function () {
    $product = Product::create(['sku_base' => 'INVTYPE-003', 'name' => 'Kit', 'status' => 'active']);

    $this->putJson('/api/v1/products/'.$product->id, ['inventory_type' => 'assembly'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['inventory_type' => 'El tipo de inventario debe ser: inventariable o no inventariable']);

    $this->postJson('/api/v1/products', ['name' => 'Instalación', 'inventory_type' => 'non_inventory'])
        ->assertCreated()
        ->assertJsonPath('data.inventory_type', 'non_inventory');
});
