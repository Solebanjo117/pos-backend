<?php

use App\Models\User;
use App\Models\VehicleChassis;
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

it('rejects legacy engine vin endpoints when engine belongs to multiple models', function () {
    $make = VehicleMake::create(['name' => 'Vin Multi Model Make']);

    $modelA = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Vin Multi Model A',
        'year_from' => 2016,
        'year_to' => 2020,
    ]);

    $modelB = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Vin Multi Model B',
        'year_from' => 2021,
        'year_to' => 2026,
    ]);

    $engine = VehicleEngine::create([
        'model_id' => null,
        'engine_code' => 'VIN-MULTI-ENG',
        'displacement' => '1.8L',
        'fuel_type' => 'Gasoline',
    ]);

    $engine->models()->sync([$modelA->id, $modelB->id]);

    $this->getJson("/api/v1/vehicle-engines/{$engine->id}/vins")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'El motor pertenece a varios modelos. Usa el endpoint de VIN por modelo y motor.');

    $this->postJson("/api/v1/vehicle-engines/{$engine->id}/vins", [
        'vin' => 'MULTIMODELVIN001',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'El motor pertenece a varios modelos. Usa el endpoint de VIN por modelo y motor.');
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

it('stores and lists vins scoped by model chassis and engine', function () {
    $make = VehicleMake::create(['name' => 'Scoped Vin Make']);

    $modelP10 = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'YARIS',
        'year_from' => 2000,
        'year_to' => 2005,
        'generation' => 'P10',
    ]);

    $modelP90 = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'YARIS',
        'year_from' => 2006,
        'year_to' => 2013,
        'generation' => 'P90',
    ]);

    $chassisP10 = VehicleChassis::create([
        'model_id' => $modelP10->id,
        'code' => 'P10',
        'name' => 'P10',
    ]);

    $chassisP90 = VehicleChassis::create([
        'model_id' => $modelP90->id,
        'code' => 'P90',
        'name' => 'P90',
    ]);

    $engineP10 = VehicleEngine::create([
        'model_id' => $modelP10->id,
        'engine_code' => '1NZFE',
    ]);

    $engineP90 = VehicleEngine::create([
        'model_id' => $modelP90->id,
        'engine_code' => '1NZFE',
    ]);

    $this->postJson("/api/v1/vehicle-models/{$modelP90->id}/chassis/{$chassisP90->id}/engines/{$engineP90->id}/vins", [
        'vin' => 'JTDBT933X01109408',
    ])
        ->assertCreated()
        ->assertJsonPath('data.model_id', $modelP90->id)
        ->assertJsonPath('data.chassis_id', $chassisP90->id)
        ->assertJsonPath('data.engine_id', $engineP90->id);

    $this->getJson("/api/v1/vehicle-models/{$modelP90->id}/chassis/{$chassisP90->id}/engines/{$engineP90->id}/vins")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.vin', 'JTDBT933X01109408');

    $this->getJson("/api/v1/vehicle-models/{$modelP10->id}/chassis/{$chassisP10->id}/engines/{$engineP10->id}/vins")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('skips duplicate scoped vins without failing', function () {
    $make = VehicleMake::create(['name' => 'Scoped Duplicate Make']);
    $model = VehicleModel::create(['make_id' => $make->id, 'name' => 'YARIS', 'year_from' => 2006, 'year_to' => 2013]);
    $chassis = VehicleChassis::create(['model_id' => $model->id, 'code' => 'P90', 'name' => 'P90']);
    $engine = VehicleEngine::create(['model_id' => $model->id, 'engine_code' => '1NZFE']);

    $payload = ['vin' => 'JTDBT933X01109408'];

    $this->postJson("/api/v1/vehicle-models/{$model->id}/chassis/{$chassis->id}/engines/{$engine->id}/vins", $payload)
        ->assertCreated();

    $this->postJson("/api/v1/vehicle-models/{$model->id}/chassis/{$chassis->id}/engines/{$engine->id}/vins", $payload)
        ->assertOk();

    $this->assertDatabaseCount('vehicle_vins', 1);
});

it('stores and lists vins scoped by model and engine without chassis', function () {
    $make = VehicleMake::create(['name' => 'Model Engine Vin Make']);
    $model = VehicleModel::create(['make_id' => $make->id, 'name' => 'YARIS', 'year_from' => 2006, 'year_to' => 2013]);
    $engine = VehicleEngine::create(['model_id' => $model->id, 'engine_code' => '1NZFE']);

    $this->postJson("/api/v1/vehicle-models/{$model->id}/engines/{$engine->id}/vins", [
        'vin' => 'JTDBT933X01109408',
    ])
        ->assertCreated()
        ->assertJsonPath('data.model_id', $model->id)
        ->assertJsonPath('data.engine_id', $engine->id)
        ->assertJsonPath('data.chassis_id', null);

    $this->getJson("/api/v1/vehicle-models/{$model->id}/engines/{$engine->id}/vins")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.vin', 'JTDBT933X01109408');
});

it('keeps same engine code isolated by model in model-engine vin scope', function () {
    $make = VehicleMake::create(['name' => 'Model Scope Make']);
    $modelA = VehicleModel::create(['make_id' => $make->id, 'name' => 'YARIS', 'year_from' => 2000, 'year_to' => 2005, 'generation' => 'P10']);
    $modelB = VehicleModel::create(['make_id' => $make->id, 'name' => 'YARIS', 'year_from' => 2006, 'year_to' => 2013, 'generation' => 'P90']);
    $engineA = VehicleEngine::create(['model_id' => $modelA->id, 'engine_code' => '1NZFE']);
    $engineB = VehicleEngine::create(['model_id' => $modelB->id, 'engine_code' => '1NZFE']);

    $this->postJson("/api/v1/vehicle-models/{$modelA->id}/engines/{$engineA->id}/vins", [
        'vin' => 'VINMODELASCOPE001',
    ])->assertCreated();

    $this->postJson("/api/v1/vehicle-models/{$modelB->id}/engines/{$engineB->id}/vins", [
        'vin' => 'VINMODELBSCOP001',
    ])->assertCreated();

    $this->getJson("/api/v1/vehicle-models/{$modelA->id}/engines/{$engineA->id}/vins")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.vin', 'VINMODELASCOPE001');

    $this->getJson("/api/v1/vehicle-models/{$modelB->id}/engines/{$engineB->id}/vins")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.vin', 'VINMODELBSCOP001');
});

it('allows same vin in model-engine and chassis-exact scopes', function () {
    $make = VehicleMake::create(['name' => 'Mixed Scope Make']);
    $model = VehicleModel::create(['make_id' => $make->id, 'name' => 'YARIS', 'year_from' => 2006, 'year_to' => 2013]);
    $engine = VehicleEngine::create(['model_id' => $model->id, 'engine_code' => '1NZFE']);
    $chassis = VehicleChassis::create(['model_id' => $model->id, 'code' => 'P90', 'name' => 'P90']);
    $model->chassis()->syncWithoutDetaching([$chassis->id]);

    $this->postJson("/api/v1/vehicle-models/{$model->id}/engines/{$engine->id}/vins", [
        'vin' => 'JTDBT933X01109408',
    ])->assertCreated();

    $this->postJson("/api/v1/vehicle-models/{$model->id}/chassis/{$chassis->id}/engines/{$engine->id}/vins", [
        'vin' => 'JTDBT933X01109408',
    ])->assertCreated();

    $this->assertDatabaseHas('vehicle_vins', [
        'model_id' => $model->id,
        'chassis_id' => null,
        'engine_id' => $engine->id,
        'vin' => 'JTDBT933X01109408',
    ]);

    $this->assertDatabaseHas('vehicle_vins', [
        'model_id' => $model->id,
        'chassis_id' => $chassis->id,
        'engine_id' => $engine->id,
        'vin' => 'JTDBT933X01109408',
    ]);
});

it('moves scoped vin between real chassis for the same model and engine', function () {
    $make = VehicleMake::create(['name' => 'Move Vin Make']);
    $model = VehicleModel::create(['make_id' => $make->id, 'name' => 'YARIS', 'year_from' => 2006, 'year_to' => 2013]);
    $engine = VehicleEngine::create(['model_id' => $model->id, 'engine_code' => '1NZFE']);
    $sourceChassis = VehicleChassis::create(['model_id' => $model->id, 'code' => 'P10', 'name' => 'P10']);
    $realChassis = VehicleChassis::create(['model_id' => $model->id, 'code' => 'P90', 'name' => 'P90']);
    $model->chassis()->syncWithoutDetaching([$sourceChassis->id, $realChassis->id]);

    $vin = VehicleVin::create([
        'model_id' => $model->id,
        'chassis_id' => $sourceChassis->id,
        'engine_id' => $engine->id,
        'vin' => 'JTDBT933X01109408',
    ]);

    $this->patchJson("/api/v1/vehicle-models/{$model->id}/chassis/{$sourceChassis->id}/engines/{$engine->id}/vins/{$vin->id}", [
        'target_chassis_id' => $realChassis->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.chassis_id', $realChassis->id);

    $this->assertDatabaseHas('vehicle_vins', [
        'id' => $vin->id,
        'model_id' => $model->id,
        'chassis_id' => $realChassis->id,
        'engine_id' => $engine->id,
        'vin' => 'JTDBT933X01109408',
    ]);
});
