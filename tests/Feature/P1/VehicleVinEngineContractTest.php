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

function createVehicleVinScope(array $overrides = []): array
{
    $make = VehicleMake::create(['name' => $overrides['make_name'] ?? 'Scoped Vin Make '.str()->random(8)]);
    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => $overrides['model_name'] ?? 'YARIS',
        'year_from' => $overrides['year_from'] ?? 2006,
        'year_to' => $overrides['year_to'] ?? 2013,
    ]);
    $chassis = VehicleChassis::create([
        'model_id' => $model->id,
        'code' => $overrides['chassis_code'] ?? 'P90',
        'name' => $overrides['chassis_name'] ?? 'P90',
    ]);
    $model->chassis()->syncWithoutDetaching([$chassis->id]);
    $engine = VehicleEngine::create([
        'model_id' => $model->id,
        'engine_code' => $overrides['engine_code'] ?? '1NZFE',
        'displacement' => $overrides['displacement'] ?? '1.5L',
        'fuel_type' => $overrides['fuel_type'] ?? 'Gasoline',
    ]);

    return [$model, $chassis, $engine];
}

it('rejects vin endpoints that are not scoped by model chassis and engine', function () {
    [$model, $chassis, $engine] = createVehicleVinScope();
    $vin = VehicleVin::create([
        'model_id' => $model->id,
        'chassis_id' => $chassis->id,
        'engine_id' => $engine->id,
        'vin' => 'FULLCARVIN0000001',
    ]);

    $this->getJson("/api/v1/vehicle-models/{$model->id}/vins")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Los VINs requieren modelo, chasis y motor.');

    $this->postJson("/api/v1/vehicle-models/{$model->id}/vins", [
        'vin' => 'MODELVIN000000001',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Los VINs requieren modelo, chasis y motor.');

    $this->deleteJson("/api/v1/vehicle-models/{$model->id}/vins/{$vin->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Los VINs requieren modelo, chasis y motor.');

    $this->getJson("/api/v1/vehicle-models/{$model->id}/engines/{$engine->id}/vins")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Los VINs requieren modelo, chasis y motor.');

    $this->postJson("/api/v1/vehicle-models/{$model->id}/engines/{$engine->id}/vins", [
        'vin' => 'ENGINEVIN00000001',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Los VINs requieren modelo, chasis y motor.');

    $this->deleteJson("/api/v1/vehicle-models/{$model->id}/engines/{$engine->id}/vins/{$vin->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Los VINs requieren modelo, chasis y motor.');

    $this->getJson("/api/v1/vehicle-engines/{$engine->id}/vins")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Los VINs requieren modelo, chasis y motor.');

    $this->postJson("/api/v1/vehicle-engines/{$engine->id}/vins", [
        'vin' => 'LEGACYVIN00000001',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Los VINs requieren modelo, chasis y motor.');

    $this->deleteJson("/api/v1/vehicle-engines/{$engine->id}/vins/{$vin->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Los VINs requieren modelo, chasis y motor.');

    $this->assertDatabaseMissing('vehicle_vins', ['vin' => 'MODELVIN000000001']);
    $this->assertDatabaseMissing('vehicle_vins', ['vin' => 'ENGINEVIN00000001']);
    $this->assertDatabaseMissing('vehicle_vins', ['vin' => 'LEGACYVIN00000001']);
    $this->assertDatabaseHas('vehicle_vins', ['id' => $vin->id]);
});

it('stores and lists vins scoped by model chassis and engine', function () {
    [$modelP90, $chassisP90, $engineP90] = createVehicleVinScope([
        'model_name' => 'YARIS P90',
        'chassis_code' => 'P90',
        'engine_code' => '1NZFE',
    ]);
    [$modelP10, $chassisP10, $engineP10] = createVehicleVinScope([
        'model_name' => 'YARIS P10',
        'year_from' => 2000,
        'year_to' => 2005,
        'chassis_code' => 'P10',
        'engine_code' => '2NZFE',
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

it('accepts vins up to 40 chars and rejects longer scoped vins', function () {
    [$model, $chassis, $engine] = createVehicleVinScope();
    $basePath = "/api/v1/vehicle-models/{$model->id}/chassis/{$chassis->id}/engines/{$engine->id}/vins";

    $this->postJson($basePath, ['vin' => 'A1'])
        ->assertCreated()
        ->assertJsonPath('data.vin', 'A1');

    $vin40 = str_repeat('B', 40);

    $this->postJson($basePath, ['vin' => $vin40])
        ->assertCreated()
        ->assertJsonPath('data.vin', $vin40);

    $this->postJson($basePath, ['vin' => str_repeat('C', 41)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['vin']);
});

it('skips duplicate scoped vins without failing', function () {
    [$model, $chassis, $engine] = createVehicleVinScope();
    $payload = ['vin' => 'JTDBT933X01109408'];
    $basePath = "/api/v1/vehicle-models/{$model->id}/chassis/{$chassis->id}/engines/{$engine->id}/vins";

    $this->postJson($basePath, $payload)->assertCreated();
    $this->postJson($basePath, $payload)->assertOk();

    $this->assertDatabaseCount('vehicle_vins', 1);
});

it('keeps the same engine code isolated by full vehicle configuration', function () {
    [$modelA, $chassisA, $engineA] = createVehicleVinScope([
        'model_name' => 'YARIS P10',
        'chassis_code' => 'P10',
        'engine_code' => '1NZFE',
    ]);
    [$modelB, $chassisB, $engineB] = createVehicleVinScope([
        'model_name' => 'YARIS P90',
        'year_from' => 2006,
        'year_to' => 2013,
        'chassis_code' => 'P90',
        'engine_code' => '1NZFE',
    ]);

    $this->postJson("/api/v1/vehicle-models/{$modelA->id}/chassis/{$chassisA->id}/engines/{$engineA->id}/vins", [
        'vin' => 'VINMODELASCOPE001',
    ])->assertCreated();

    $this->postJson("/api/v1/vehicle-models/{$modelB->id}/chassis/{$chassisB->id}/engines/{$engineB->id}/vins", [
        'vin' => 'VINMODELBSCOP001',
    ])->assertCreated();

    $this->getJson("/api/v1/vehicle-models/{$modelA->id}/chassis/{$chassisA->id}/engines/{$engineA->id}/vins")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.vin', 'VINMODELASCOPE001');

    $this->getJson("/api/v1/vehicle-models/{$modelB->id}/chassis/{$chassisB->id}/engines/{$engineB->id}/vins")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.vin', 'VINMODELBSCOP001');
});

it('does not delete scoped car vins through unscoped endpoints', function () {
    [$model, $chassis, $engine] = createVehicleVinScope();
    $vin = VehicleVin::create([
        'model_id' => $model->id,
        'chassis_id' => $chassis->id,
        'engine_id' => $engine->id,
        'vin' => 'FULLCARVIN0000002',
    ]);

    $this->deleteJson("/api/v1/vehicle-models/{$model->id}/vins/{$vin->id}")
        ->assertUnprocessable();
    $this->deleteJson("/api/v1/vehicle-models/{$model->id}/engines/{$engine->id}/vins/{$vin->id}")
        ->assertUnprocessable();
    $this->deleteJson("/api/v1/vehicle-engines/{$engine->id}/vins/{$vin->id}")
        ->assertUnprocessable();

    $this->assertDatabaseHas('vehicle_vins', ['id' => $vin->id]);
});

it('deletes vins only through the matching full vehicle configuration', function () {
    [$modelA, $chassisA, $engineA] = createVehicleVinScope([
        'model_name' => 'Delete Scope A',
        'chassis_code' => 'DS-A',
        'engine_code' => 'DS-ENG-A',
    ]);
    [$modelB, $chassisB, $engineB] = createVehicleVinScope([
        'model_name' => 'Delete Scope B',
        'chassis_code' => 'DS-B',
        'engine_code' => 'DS-ENG-B',
    ]);

    $vinA = VehicleVin::create([
        'model_id' => $modelA->id,
        'chassis_id' => $chassisA->id,
        'engine_id' => $engineA->id,
        'vin' => 'FULLCARVIN0000003',
    ]);
    $vinB = VehicleVin::create([
        'model_id' => $modelB->id,
        'chassis_id' => $chassisB->id,
        'engine_id' => $engineB->id,
        'vin' => 'FULLCARVIN0000004',
    ]);

    $this->deleteJson("/api/v1/vehicle-models/{$modelA->id}/chassis/{$chassisA->id}/engines/{$engineA->id}/vins/{$vinB->id}")
        ->assertNotFound();

    $this->deleteJson("/api/v1/vehicle-models/{$modelA->id}/chassis/{$chassisA->id}/engines/{$engineA->id}/vins/{$vinA->id}")
        ->assertOk();

    $this->assertDatabaseMissing('vehicle_vins', ['id' => $vinA->id]);
    $this->assertDatabaseHas('vehicle_vins', ['id' => $vinB->id]);
});

it('moves scoped vin between real chassis for the same model and engine', function () {
    [$model, $sourceChassis, $engine] = createVehicleVinScope(['chassis_code' => 'P10']);
    $targetChassis = VehicleChassis::create(['model_id' => $model->id, 'code' => 'P90', 'name' => 'P90']);
    $model->chassis()->syncWithoutDetaching([$targetChassis->id]);

    $vin = VehicleVin::create([
        'model_id' => $model->id,
        'chassis_id' => $sourceChassis->id,
        'engine_id' => $engine->id,
        'vin' => 'JTDBT933X01109408',
    ]);

    $this->patchJson("/api/v1/vehicle-models/{$model->id}/chassis/{$sourceChassis->id}/engines/{$engine->id}/vins/{$vin->id}", [
        'target_chassis_id' => $targetChassis->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.chassis_id', $targetChassis->id);

    $this->assertDatabaseHas('vehicle_vins', [
        'id' => $vin->id,
        'model_id' => $model->id,
        'chassis_id' => $targetChassis->id,
        'engine_id' => $engine->id,
        'vin' => 'JTDBT933X01109408',
    ]);
});
