<?php

use App\Models\User;
use App\Models\VehicleChassis;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $this->actingAs($admin);
});

it('stores chassis with multiple models and returns model_ids', function () {
    $makeA = VehicleMake::create(['name' => 'Marca A']);
    $makeB = VehicleMake::create(['name' => 'Marca B']);

    $modelA = VehicleModel::create([
        'make_id' => $makeA->id,
        'name' => 'Modelo A',
        'year_from' => 2015,
        'year_to' => 2020,
    ]);

    $modelB = VehicleModel::create([
        'make_id' => $makeB->id,
        'name' => 'Modelo B',
        'year_from' => 2018,
        'year_to' => 2024,
    ]);

    $response = $this->postJson('/api/v1/vehicle-chassis', [
        'code' => 'MM-CH-001',
        'name' => 'Chasis Multi Modelo',
        'model_ids' => [$modelA->id, $modelB->id],
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.model_id', $modelA->id)
        ->assertJsonPath('data.model_ids.0', $modelA->id)
        ->assertJsonPath('data.model_ids.1', $modelB->id);

    $chassisId = $response->json('data.id');

    $this->assertDatabaseHas('vehicle_chassis', [
        'id' => $chassisId,
        'code' => 'MM-CH-001',
        'model_id' => $modelA->id,
    ]);

    $this->assertDatabaseHas('vehicle_chassis_model', [
        'chassis_id' => $chassisId,
        'model_id' => $modelA->id,
    ]);

    $this->assertDatabaseHas('vehicle_chassis_model', [
        'chassis_id' => $chassisId,
        'model_id' => $modelB->id,
    ]);
});

it('updates chassis model associations using model_ids sync', function () {
    $make = VehicleMake::create(['name' => 'Marca Sync']);

    $model1 = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo 1',
        'year_from' => 2010,
        'year_to' => 2015,
    ]);

    $model2 = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo 2',
        'year_from' => 2016,
        'year_to' => 2020,
    ]);

    $model3 = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo 3',
        'year_from' => 2021,
        'year_to' => 2026,
    ]);

    $chassis = VehicleChassis::create([
        'code' => 'MM-CH-002',
        'name' => 'Antes del sync',
        'model_id' => $model1->id,
    ]);

    $chassis->models()->sync([$model1->id]);

    $response = $this->putJson("/api/v1/vehicle-chassis/{$chassis->id}", [
        'model_ids' => [$model2->id, $model3->id],
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.model_id', $model2->id)
        ->assertJsonPath('data.model_ids.0', $model2->id)
        ->assertJsonPath('data.model_ids.1', $model3->id);

    $this->assertDatabaseHas('vehicle_chassis', [
        'id' => $chassis->id,
        'model_id' => $model2->id,
    ]);

    $this->assertDatabaseMissing('vehicle_chassis_model', [
        'chassis_id' => $chassis->id,
        'model_id' => $model1->id,
    ]);

    $this->assertDatabaseHas('vehicle_chassis_model', [
        'chassis_id' => $chassis->id,
        'model_id' => $model2->id,
    ]);

    $this->assertDatabaseHas('vehicle_chassis_model', [
        'chassis_id' => $chassis->id,
        'model_id' => $model3->id,
    ]);
});

it('accepts legacy model_id payload and keeps pivot in sync', function () {
    $make = VehicleMake::create(['name' => 'Marca Legacy']);

    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Legacy',
        'year_from' => 2012,
        'year_to' => 2019,
    ]);

    $response = $this->postJson('/api/v1/vehicle-chassis', [
        'code' => 'MM-CH-003',
        'name' => 'Chasis Legacy',
        'model_id' => $model->id,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.model_id', $model->id)
        ->assertJsonPath('data.model_ids.0', $model->id);

    $chassisId = $response->json('data.id');

    $this->assertDatabaseHas('vehicle_chassis_model', [
        'chassis_id' => $chassisId,
        'model_id' => $model->id,
    ]);
});

it('returns multi-associated chassis from byModel endpoint', function () {
    $make = VehicleMake::create(['name' => 'Marca Consulta']);

    $modelA = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Consulta A',
        'year_from' => 2011,
        'year_to' => 2018,
    ]);

    $modelB = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Consulta B',
        'year_from' => 2019,
        'year_to' => 2025,
    ]);

    $chassis = VehicleChassis::create([
        'code' => 'MM-CH-004',
        'name' => 'Chasis Consulta',
        'model_id' => $modelA->id,
    ]);

    $chassis->models()->sync([$modelA->id, $modelB->id]);

    $response = $this->getJson("/api/v1/vehicle-models/{$modelB->id}/chassis");

    $response->assertOk();

    $returnedIds = collect($response->json('data'))->pluck('id')->all();
    expect($returnedIds)->toContain($chassis->id);
});
