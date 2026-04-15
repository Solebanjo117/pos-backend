<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $warehouse = Warehouse::create([
        'code' => 'W-NAME',
        'name' => 'Warehouse Name',
    ]);

    $user = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);

    $user->warehouses()->attach($warehouse->id);
    $this->actingAs($user);
});

it('keeps provided name when creating product with vehicle compatibility', function () {
    $category = Category::create([
        'name' => 'Pruebas',
        'slug' => 'pruebas-' . uniqid(),
        'priority' => 1,
    ]);

    $make = VehicleMake::create([
        'name' => 'Chevrolet',
    ]);

    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Silverado 1500',
        'year_from' => 2014,
        'year_to' => 2023,
    ]);

    $engine = VehicleEngine::create([
        'model_id' => $model->id,
        'engine_code' => '5.3L V8',
        'displacement' => '5.3L',
    ]);

    $providedName = 'Pruebas - LARGE: 15cm, ANCHO: 30cm - Chevrolet Silverado 1500 (5.3L V8)';

    $response = $this->postJson('/api/v1/products', [
        'name' => $providedName,
        'category_id' => $category->id,
        'vehicle_compatibility' => [$engine->id],
    ]);

    $response
        ->assertStatus(201)
        ->assertJsonPath('data.name', $providedName);

    $productId = $response->json('data.id');

    expect($productId)->not->toBeNull();
    expect(Product::findOrFail($productId)->name)->toBe($providedName);

    $this->assertDatabaseHas('product_vehicle_compatibility', [
        'product_id' => $productId,
        'engine_id' => $engine->id,
    ]);
});
