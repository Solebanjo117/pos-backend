<?php

use App\Models\Product;
use App\Models\ProductVehicleCompatibility;
use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $warehouse = Warehouse::create([
        'code' => 'W-VEH',
        'name' => 'Warehouse Vehicles',
    ]);

    $user = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);

    $user->warehouses()->attach($warehouse->id);
    $this->actingAs($user);
});

test('products index exposes compatible vehicles summarized for the list', function () {
    $make = VehicleMake::create(['name' => 'Toyota']);
    $model = VehicleModel::create(['make_id' => $make->id, 'name' => 'Tercel', 'year_from' => 1995, 'year_to' => 1999]);
    $engine = VehicleEngine::create(['make_id' => $make->id, 'model_id' => $model->id, 'engine_code' => '5E-FE', 'displacement' => '1.5L']);

    $product = Product::create(['sku_base' => 'VEH-001', 'name' => 'Terminal cremallera', 'status' => 'active']);

    ProductVehicleCompatibility::create(['product_id' => $product->id, 'scope_type' => 'model', 'make_id' => $make->id, 'model_id' => $model->id]);
    ProductVehicleCompatibility::create(['product_id' => $product->id, 'scope_type' => 'engine', 'engine_id' => $engine->id]);
    ProductVehicleCompatibility::create(['product_id' => $product->id, 'scope_type' => 'raw', 'raw_vehicle_text' => 'Corolla AE100']);

    $response = $this->getJson('/api/v1/products?per_page=50')->assertOk();

    $resource = collect($response->json('data'))->firstWhere('id', $product->id);

    expect(data_get($resource, 'vehicles'))->toBe([
        ['label' => 'Toyota Tercel 1995-1999', 'detail' => null],
        ['label' => 'Toyota Tercel 1995-1999', 'detail' => '1.5L 5E-FE'],
        ['label' => 'Corolla AE100', 'detail' => null],
    ]);
});

test('products index returns an empty vehicle list when there is no compatibility', function () {
    $product = Product::create(['sku_base' => 'VEH-002', 'name' => 'Aceite', 'status' => 'active']);

    $response = $this->getJson('/api/v1/products?per_page=50')->assertOk();

    expect(data_get(collect($response->json('data'))->firstWhere('id', $product->id), 'vehicles'))->toBe([]);
});
