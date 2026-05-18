<?php

use App\Models\Product;
use App\Models\ProductVehicleCompatibility;
use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('creates product with all_engines scoped to specific model', function () {
    $make = VehicleMake::create(['name' => 'Hyundai']);
    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Accent',
        'year_from' => 2018,
        'year_to' => 2022,
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/products', [
        'name' => 'Filtro Aceite Accent All Engines',
        'status' => 'active',
        'vehicle_compatibility' => [
            'engines' => [],
            'chassis' => [],
            'all_engines' => true,
            'all_chassis' => false,
            'model_id' => $model->id,
        ],
    ]);

    $response->assertCreated();

    $product = Product::where('name', 'Filtro Aceite Accent All Engines')->first();
    $compat = $product->vehicleCompatibility;

    $allEnginesRecord = $compat->firstWhere('all_engines', true);

    expect($allEnginesRecord)->not->toBeNull()
        ->and($allEnginesRecord->scope_type)->toBe('model')
        ->and($allEnginesRecord->model_id)->toBe($model->id)
        ->and($allEnginesRecord->make_id)->toBe($make->id);
});

it('creates product with all_engines as universal when no model_id', function () {
    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/products', [
        'name' => 'Filtro Universal All Engines',
        'status' => 'active',
        'vehicle_compatibility' => [
            'engines' => [],
            'chassis' => [],
            'all_engines' => true,
            'all_chassis' => false,
        ],
    ]);

    $response->assertCreated();

    $product = Product::where('name', 'Filtro Universal All Engines')->first();
    $compat = $product->vehicleCompatibility;

    $allEnginesRecord = $compat->firstWhere('all_engines', true);

    expect($allEnginesRecord)->not->toBeNull()
        ->and($allEnginesRecord->scope_type)->toBe('universal')
        ->and($allEnginesRecord->model_id)->toBeNull();
});

it('creates product with all_chassis scoped to specific model', function () {
    $make = VehicleMake::create(['name' => 'Kia']);
    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Rio',
        'year_from' => 2020,
        'year_to' => 2024,
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/products', [
        'name' => 'Pastilla Freno Rio All Chassis',
        'status' => 'active',
        'vehicle_compatibility' => [
            'engines' => [],
            'chassis' => [],
            'all_engines' => false,
            'all_chassis' => true,
            'model_id' => $model->id,
        ],
    ]);

    $response->assertCreated();

    $product = Product::where('name', 'Pastilla Freno Rio All Chassis')->first();
    $compat = $product->vehicleCompatibility;

    $allChassisRecord = $compat->firstWhere('all_chassis', true);

    expect($allChassisRecord)->not->toBeNull()
        ->and($allChassisRecord->scope_type)->toBe('model')
        ->and($allChassisRecord->model_id)->toBe($model->id)
        ->and($allChassisRecord->make_id)->toBe($make->id);
});

it('creates product with specific engines as structured objects', function () {
    $make = VehicleMake::create(['name' => 'Hyundai']);
    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Elantra',
        'year_from' => 2019,
        'year_to' => 2023,
    ]);
    $engine = VehicleEngine::create([
        'engine_code' => 'GAMMA G4 FA',
        'model_id' => $model->id,
        'fuel_type' => 'Gasoline',
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/products', [
        'name' => 'Filtro Aire Elantra Gamma',
        'status' => 'active',
        'vehicle_compatibility' => [
            'engines' => [
                ['engine_id' => $engine->id],
            ],
            'chassis' => [],
            'all_engines' => false,
            'all_chassis' => false,
        ],
    ]);

    $response->assertCreated();

    $product = Product::where('name', 'Filtro Aire Elantra Gamma')->first();
    $compat = $product->vehicleCompatibility;

    expect($compat)->toHaveCount(1)
        ->and($compat->first()->engine_id)->toBe($engine->id)
        ->and($compat->first()->scope_type)->toBe('engine');
});

it('engine code with internal spaces is accepted', function () {
    $make = VehicleMake::create(['name' => 'Hyundai']);
    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Sonata',
        'year_from' => 2020,
        'year_to' => 2025,
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/vehicle-engines', [
        'engine_code' => 'Gamma G4 FA',
        'model_id' => $model->id,
        'model_ids' => [$model->id],
        'fuel_type' => 'Gasoline',
    ]);

    $response->assertCreated();
    expect($response->json('data.engine_code'))->toBe('GAMMA G4 FA');
});

it('engine creation with valid fuel_type succeeds', function () {
    $make = VehicleMake::create(['name' => 'Kia']);
    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Niro',
        'year_from' => 2022,
        'year_to' => 2025,
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/vehicle-engines', [
        'engine_code' => 'GDI1.6',
        'model_id' => $model->id,
        'model_ids' => [$model->id],
        'fuel_type' => 'Gasoline',
    ]);

    $response->assertCreated();
    expect($response->json('data.fuel_type'))->toBe('Gasoline');
});

it('engine creation with invalid fuel_type is rejected', function () {
    $make = VehicleMake::create(['name' => 'Kia']);
    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Sportage',
        'year_from' => 2021,
        'year_to' => 2025,
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/vehicle-engines', [
        'engine_code' => 'THETA2.0',
        'model_id' => $model->id,
        'model_ids' => [$model->id],
        'fuel_type' => 'N/A',
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.fuel_type'))->not->toBeNull();
});

it('engine code with excessive whitespace is collapsed', function () {
    $make = VehicleMake::create(['name' => 'Hyundai']);
    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Tucson',
        'year_from' => 2022,
        'year_to' => 2025,
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/vehicle-engines', [
        'engine_code' => "Gamma   G4\tFA",
        'model_id' => $model->id,
        'model_ids' => [$model->id],
        'fuel_type' => 'Gasoline',
    ]);

    $response->assertCreated();
    expect($response->json('data.engine_code'))->toBe('GAMMA G4 FA');
});

it('model-scoped all_engines does not create extra all_chassis record', function () {
    $make = VehicleMake::create(['name' => 'Hyundai']);
    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Accent',
        'year_from' => 2018,
        'year_to' => 2022,
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/products', [
        'name' => 'Filtro Solo AllEngines',
        'status' => 'active',
        'vehicle_compatibility' => [
            'engines' => [],
            'chassis' => [],
            'all_engines' => true,
            'all_chassis' => false,
            'model_id' => $model->id,
        ],
    ]);

    $response->assertCreated();

    $product = Product::where('name', 'Filtro Solo AllEngines')->first();
    $compat = $product->vehicleCompatibility;

    // Only one record: all_engines scoped to model. No extra all_chassis record.
    expect($compat)->toHaveCount(1)
        ->and($compat->first()->all_engines)->toBeTrue()
        ->and($compat->first()->all_chassis)->toBeFalse();
});

it('direct compatibility endpoint creates model-scoped all_engines record', function () {
    $make = VehicleMake::create(['name' => 'Honda']);
    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Civic',
        'year_from' => 2018,
        'year_to' => 2022,
    ]);

    $product = Product::create([
        'sku_base' => 'FILTRO-CIVIC-DIRECT',
        'name' => 'Filtro Civic',
        'status' => 'active',
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    actingAs($user);

    $response = $this->postJson('/api/v1/products/compatibility', [
        'product_id' => $product->id,
        'scope_type' => 'model',
        'model_id' => $model->id,
        'all_engines' => true,
        'all_chassis' => false,
    ]);

    $response->assertCreated();

    $compat = ProductVehicleCompatibility::where('product_id', $product->id)->first();

    expect($compat)->not->toBeNull()
        ->and($compat->scope_type)->toBe('model')
        ->and($compat->model_id)->toBe($model->id)
        ->and($compat->all_engines)->toBeTrue()
        ->and($compat->all_chassis)->toBeFalse();
});
