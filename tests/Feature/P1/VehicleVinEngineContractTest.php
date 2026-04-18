<?php

use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\VehicleVin;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $this->actingAs($admin);
});

it('lists vins by engine id only', function () {
    $make = VehicleMake::create(['name' => 'Vin Engine Make']);

    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Vin Engine Model',
        'year_from' => 2015,
        'year_to' => 2024,
    ]);

    $engineA = VehicleEngine::create([
        'model_id' => $model->id,
        'engine_code' => 'VIN-ENG-A',
        'displacement' => '2.0L',
        'fuel_type' => 'Gasoline',
    ]);

    $engineB = VehicleEngine::create([
        'model_id' => $model->id,
        'engine_code' => 'VIN-ENG-B',
        'displacement' => '2.4L',
        'fuel_type' => 'Gasoline',
    ]);

    $vinForEngineA = VehicleVin::create([
        'model_id' => $model->id,
        'engine_id' => $engineA->id,
        'vin' => 'ENGVIN00000000001',
    ]);

    VehicleVin::create([
        'model_id' => $model->id,
        'engine_id' => $engineB->id,
        'vin' => 'ENGVIN00000000002',
    ]);

    VehicleVin::create([
        'model_id' => $model->id,
        'engine_id' => null,
        'vin' => 'MODELVIN000000001',
    ]);

    $response = $this->getJson("/api/v1/vehicle-engines/{$engineA->id}/vins");

    $response
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $vinForEngineA->id)
        ->assertJsonPath('data.0.engine_id', $engineA->id);
});

it('stores vins by engine id and resolves model from pivot when needed', function () {
    $make = VehicleMake::create(['name' => 'Vin Pivot Make']);

    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Vin Pivot Model',
        'year_from' => 2016,
        'year_to' => 2025,
    ]);

    $engine = VehicleEngine::create([
        'model_id' => null,
        'engine_code' => 'VIN-PIVOT-ENG',
        'displacement' => '1.8L',
        'fuel_type' => 'Gasoline',
    ]);

    $engine->models()->sync([$model->id]);

    $response = $this->postJson("/api/v1/vehicle-engines/{$engine->id}/vins", [
        'vin' => 'ENGVIN00000000003',
        'notes' => 'Engine-scoped VIN',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.engine_id', $engine->id)
        ->assertJsonPath('data.model_id', $model->id)
        ->assertJsonPath('data.vin', 'ENGVIN00000000003');

    $this->assertDatabaseHas('vehicle_vins', [
        'engine_id' => $engine->id,
        'model_id' => $model->id,
        'vin' => 'ENGVIN00000000003',
    ]);
});

it('deletes vins by engine id without crossing engines', function () {
    $make = VehicleMake::create(['name' => 'Vin Delete Make']);

    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Vin Delete Model',
        'year_from' => 2017,
        'year_to' => 2026,
    ]);

    $engineA = VehicleEngine::create([
        'model_id' => $model->id,
        'engine_code' => 'VIN-DEL-A',
        'displacement' => '2.0L',
        'fuel_type' => 'Gasoline',
    ]);

    $engineB = VehicleEngine::create([
        'model_id' => $model->id,
        'engine_code' => 'VIN-DEL-B',
        'displacement' => '2.5L',
        'fuel_type' => 'Gasoline',
    ]);

    $vinA = VehicleVin::create([
        'model_id' => $model->id,
        'engine_id' => $engineA->id,
        'vin' => 'ENGVIN00000000004',
    ]);

    $vinB = VehicleVin::create([
        'model_id' => $model->id,
        'engine_id' => $engineB->id,
        'vin' => 'ENGVIN00000000005',
    ]);

    $this->deleteJson("/api/v1/vehicle-engines/{$engineA->id}/vins/{$vinB->id}")
        ->assertNotFound();

    $this->deleteJson("/api/v1/vehicle-engines/{$engineA->id}/vins/{$vinA->id}")
        ->assertOk();

    $this->assertDatabaseMissing('vehicle_vins', ['id' => $vinA->id]);
    $this->assertDatabaseHas('vehicle_vins', ['id' => $vinB->id]);
});

it('keeps model vin endpoints compatible', function () {
    $make = VehicleMake::create(['name' => 'Vin Model Make']);

    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Vin Model',
        'year_from' => 2014,
        'year_to' => 2023,
    ]);

    $storeResponse = $this->postJson("/api/v1/vehicle-models/{$model->id}/vins", [
        'vin' => 'MODELVIN000000002',
        'notes' => 'Model-scoped VIN',
    ]);

    $storeResponse
        ->assertCreated()
        ->assertJsonPath('data.model_id', $model->id)
        ->assertJsonPath('data.engine_id', null);

    $vinId = $storeResponse->json('data.id');

    $this->getJson("/api/v1/vehicle-models/{$model->id}/vins")
        ->assertOk()
        ->assertJsonPath('data.0.id', $vinId);

    $this->deleteJson("/api/v1/vehicle-models/{$model->id}/vins/{$vinId}")
        ->assertOk();

    $this->assertDatabaseMissing('vehicle_vins', ['id' => $vinId]);
});

it('accepts vins up to 40 chars and rejects 41 chars for engine endpoints', function () {
    $make = VehicleMake::create(['name' => 'Vin Length Engine Make']);

    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Vin Length Engine Model',
        'year_from' => 2018,
        'year_to' => 2026,
    ]);

    $engine = VehicleEngine::create([
        'model_id' => $model->id,
        'engine_code' => 'VIN-LEN-ENG',
        'displacement' => '2.2L',
        'fuel_type' => 'Gasoline',
    ]);

    $this->postJson("/api/v1/vehicle-engines/{$engine->id}/vins", [
        'vin' => 'A1',
    ])
        ->assertCreated()
        ->assertJsonPath('data.vin', 'A1');

    $vin40 = str_repeat('B', 40);

    $this->postJson("/api/v1/vehicle-engines/{$engine->id}/vins", [
        'vin' => $vin40,
    ])
        ->assertCreated()
        ->assertJsonPath('data.vin', $vin40);

    $this->postJson("/api/v1/vehicle-engines/{$engine->id}/vins", [
        'vin' => str_repeat('C', 41),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['vin']);
});

it('accepts vins up to 40 chars and rejects 41 chars for model endpoints', function () {
    $make = VehicleMake::create(['name' => 'Vin Length Model Make']);

    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Vin Length Model',
        'year_from' => 2012,
        'year_to' => 2024,
    ]);

    $this->postJson("/api/v1/vehicle-models/{$model->id}/vins", [
        'vin' => 'ZX1',
    ])
        ->assertCreated()
        ->assertJsonPath('data.vin', 'ZX1');

    $vin40 = str_repeat('D', 40);

    $this->postJson("/api/v1/vehicle-models/{$model->id}/vins", [
        'vin' => $vin40,
    ])
        ->assertCreated()
        ->assertJsonPath('data.vin', $vin40);

    $this->postJson("/api/v1/vehicle-models/{$model->id}/vins", [
        'vin' => str_repeat('E', 41),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['vin']);
});
