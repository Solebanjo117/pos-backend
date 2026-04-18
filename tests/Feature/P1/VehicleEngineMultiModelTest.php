<?php

use App\Models\User;
use App\Models\VehicleEngine;
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

it('stores engine with multiple models and returns model_ids', function () {
    $makeA = VehicleMake::create(['name' => 'Marca Motor A']);
    $makeB = VehicleMake::create(['name' => 'Marca Motor B']);

    $modelA = VehicleModel::create([
        'make_id' => $makeA->id,
        'name' => 'Modelo Motor A',
        'year_from' => 2014,
        'year_to' => 2020,
    ]);

    $modelB = VehicleModel::create([
        'make_id' => $makeB->id,
        'name' => 'Modelo Motor B',
        'year_from' => 2018,
        'year_to' => 2025,
    ]);

    $response = $this->postJson('/api/v1/vehicle-engines', [
        'engine_code' => 'MM-EN-001',
        'displacement' => '2.0L',
        'fuel_type' => 'Gasoline',
        'model_ids' => [$modelA->id, $modelB->id],
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.model_id', $modelA->id)
        ->assertJsonPath('data.model_ids.0', $modelA->id)
        ->assertJsonPath('data.model_ids.1', $modelB->id);

    $engineId = $response->json('data.id');

    $this->assertDatabaseHas('vehicle_engines', [
        'id' => $engineId,
        'engine_code' => 'MM-EN-001',
        'model_id' => $modelA->id,
    ]);

    $this->assertDatabaseHas('vehicle_engine_model', [
        'engine_id' => $engineId,
        'model_id' => $modelA->id,
    ]);

    $this->assertDatabaseHas('vehicle_engine_model', [
        'engine_id' => $engineId,
        'model_id' => $modelB->id,
    ]);
});

it('updates engine model associations using model_ids sync', function () {
    $make = VehicleMake::create(['name' => 'Marca Motor Sync']);

    $model1 = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Motor 1',
        'year_from' => 2010,
        'year_to' => 2016,
    ]);

    $model2 = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Motor 2',
        'year_from' => 2017,
        'year_to' => 2021,
    ]);

    $model3 = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Motor 3',
        'year_from' => 2022,
        'year_to' => 2026,
    ]);

    $engine = VehicleEngine::create([
        'engine_code' => 'MM-EN-002',
        'displacement' => '1.8L',
        'fuel_type' => 'Gasoline',
        'model_id' => $model1->id,
    ]);

    $engine->models()->sync([$model1->id]);

    $response = $this->putJson("/api/v1/vehicle-engines/{$engine->id}", [
        'model_ids' => [$model2->id, $model3->id],
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.model_id', $model2->id)
        ->assertJsonPath('data.model_ids.0', $model2->id)
        ->assertJsonPath('data.model_ids.1', $model3->id);

    $this->assertDatabaseHas('vehicle_engines', [
        'id' => $engine->id,
        'model_id' => $model2->id,
    ]);

    $this->assertDatabaseMissing('vehicle_engine_model', [
        'engine_id' => $engine->id,
        'model_id' => $model1->id,
    ]);

    $this->assertDatabaseHas('vehicle_engine_model', [
        'engine_id' => $engine->id,
        'model_id' => $model2->id,
    ]);

    $this->assertDatabaseHas('vehicle_engine_model', [
        'engine_id' => $engine->id,
        'model_id' => $model3->id,
    ]);
});

it('accepts legacy model_id payload and keeps pivot in sync', function () {
    $make = VehicleMake::create(['name' => 'Marca Motor Legacy']);

    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Motor Legacy',
        'year_from' => 2013,
        'year_to' => 2019,
    ]);

    $response = $this->postJson('/api/v1/vehicle-engines', [
        'engine_code' => 'MM-EN-003',
        'displacement' => '3.0L',
        'fuel_type' => 'Diesel',
        'model_id' => $model->id,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.model_id', $model->id)
        ->assertJsonPath('data.model_ids.0', $model->id);

    $engineId = $response->json('data.id');

    $this->assertDatabaseHas('vehicle_engine_model', [
        'engine_id' => $engineId,
        'model_id' => $model->id,
    ]);
});

it('returns multi-associated engines from byModel endpoint', function () {
    $make = VehicleMake::create(['name' => 'Marca Motor Consulta']);

    $modelA = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Motor Consulta A',
        'year_from' => 2012,
        'year_to' => 2018,
    ]);

    $modelB = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Motor Consulta B',
        'year_from' => 2019,
        'year_to' => 2025,
    ]);

    $engine = VehicleEngine::create([
        'engine_code' => 'MM-EN-004',
        'displacement' => '2.5L',
        'fuel_type' => 'Gasoline',
        'model_id' => $modelA->id,
    ]);

    $engine->models()->sync([$modelA->id, $modelB->id]);

    $response = $this->getJson("/api/v1/vehicle-models/{$modelB->id}/engines");

    $response->assertOk();

    $returnedIds = collect($response->json('data'))->pluck('id')->all();
    expect($returnedIds)->toContain($engine->id);
});
