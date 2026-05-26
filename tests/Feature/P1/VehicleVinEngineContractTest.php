<?php

use App\Models\User;
use App\Models\VehicleChassis;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\VehicleVin;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
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

it('stores lists and deletes vins scoped by model and engine without chassis', function (): void {
    [$model, , $engine] = createVehicleVinScope();

    $this->postJson("/api/v1/vehicle-models/{$model->id}/engines/{$engine->id}/vins", [
        'vin' => 'jtdbt933x01109408',
    ])
        ->assertCreated()
        ->assertJsonPath('data.model_id', $model->id)
        ->assertJsonPath('data.chassis_id', null)
        ->assertJsonPath('data.engine_id', $engine->id)
        ->assertJsonPath('data.vin', 'JTDBT933X01109408');

    $vinId = VehicleVin::query()->where('vin', 'JTDBT933X01109408')->value('id');

    $this->getJson("/api/v1/vehicle-models/{$model->id}/engines/{$engine->id}/vins")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.vin', 'JTDBT933X01109408')
        ->assertJsonPath('data.0.chassis_id', null);

    $this->deleteJson("/api/v1/vehicle-models/{$model->id}/engines/{$engine->id}/vins/{$vinId}")
        ->assertOk();

    $this->assertDatabaseMissing('vehicle_vins', ['id' => $vinId]);
});

it('allows vins for models without chassis', function (): void {
    $make = VehicleMake::create(['name' => 'Chevrolet']);
    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'AVEO',
        'year_from' => 2008,
        'year_to' => 2013,
    ]);
    $engine = VehicleEngine::create([
        'model_id' => $model->id,
        'engine_code' => 'F14D4',
        'displacement' => '1.4L',
        'fuel_type' => 'Gasoline',
    ]);

    $this->postJson("/api/v1/vehicle-models/{$model->id}/engines/{$engine->id}/vins", [
        'vin' => 'NOCHASISVIN001',
    ])
        ->assertCreated()
        ->assertJsonPath('data.chassis_id', null);
});

it('keeps same engine code and vin isolated by model id', function (): void {
    [$modelA, , $engineA] = createVehicleVinScope([
        'model_name' => 'YARIS P10',
        'engine_code' => '1NZFE',
    ]);
    [$modelB, , $engineB] = createVehicleVinScope([
        'model_name' => 'YARIS P90',
        'year_from' => 2006,
        'year_to' => 2013,
        'engine_code' => '1NZFE',
    ]);

    $payload = ['vin' => 'SHAREDVIN001'];

    $this->postJson("/api/v1/vehicle-models/{$modelA->id}/engines/{$engineA->id}/vins", $payload)
        ->assertCreated();
    $this->postJson("/api/v1/vehicle-models/{$modelB->id}/engines/{$engineB->id}/vins", $payload)
        ->assertCreated();

    $this->getJson("/api/v1/vehicle-models/{$modelA->id}/engines/{$engineA->id}/vins")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.vin', 'SHAREDVIN001');

    $this->getJson("/api/v1/vehicle-models/{$modelB->id}/engines/{$engineB->id}/vins")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.vin', 'SHAREDVIN001');

    $this->assertDatabaseCount('vehicle_vins', 2);
});

it('skips duplicate model engine vins without failing', function (): void {
    [$model, , $engine] = createVehicleVinScope();
    $payload = ['vin' => 'JTDBT933X01109408'];
    $basePath = "/api/v1/vehicle-models/{$model->id}/engines/{$engine->id}/vins";

    $this->postJson($basePath, $payload)->assertCreated();
    $this->postJson($basePath, $payload)->assertOk();

    $this->assertDatabaseCount('vehicle_vins', 1);
});

it('keeps legacy chassis vin routes as aliases for model and engine scope', function (): void {
    [$model, $chassis, $engine] = createVehicleVinScope();

    $legacyPath = "/api/v1/vehicle-models/{$model->id}/chassis/{$chassis->id}/engines/{$engine->id}/vins";

    $this->postJson($legacyPath, ['vin' => 'LEGACYALIASVIN001'])
        ->assertCreated()
        ->assertJsonPath('data.chassis_id', null);

    $vinId = VehicleVin::query()->where('vin', 'LEGACYALIASVIN001')->value('id');

    $this->getJson($legacyPath)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $vinId);

    $this->deleteJson("{$legacyPath}/{$vinId}")
        ->assertOk();

    $this->assertDatabaseMissing('vehicle_vins', ['id' => $vinId]);
});

it('rejects moving vins between chassis because vins are model and engine scoped', function (): void {
    [$model, $chassis, $engine] = createVehicleVinScope();
    $targetChassis = VehicleChassis::create(['model_id' => $model->id, 'code' => 'P10', 'name' => 'P10']);
    $model->chassis()->syncWithoutDetaching([$targetChassis->id]);
    $vin = VehicleVin::create([
        'model_id' => $model->id,
        'engine_id' => $engine->id,
        'vin' => 'MOVEVIN001',
    ]);

    $this->patchJson("/api/v1/vehicle-models/{$model->id}/chassis/{$chassis->id}/engines/{$engine->id}/vins/{$vin->id}", [
        'target_chassis_id' => $targetChassis->id,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Los VINs ahora se administran por modelo y motor; no se mueven por chasis.');
});

it('rejects model engine vins when the engine does not belong to the model', function (): void {
    [$model] = createVehicleVinScope();
    [, , $otherEngine] = createVehicleVinScope(['model_name' => 'COROLLA', 'engine_code' => '2ZR']);

    $this->postJson("/api/v1/vehicle-models/{$model->id}/engines/{$otherEngine->id}/vins", [
        'vin' => 'BADENGINEVIN001',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'El motor no pertenece al modelo seleccionado.');
});
