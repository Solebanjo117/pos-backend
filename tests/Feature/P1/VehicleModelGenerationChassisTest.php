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

it('auto-creates chassis from generation when storing a model', function () {
    $make = VehicleMake::create(['name' => 'Generation Make']);

    $response = $this->postJson('/api/v1/vehicle-models', [
        'make_id' => $make->id,
        'name' => 'Generation Model Store',
        'year_from' => 2018,
        'year_to' => 2025,
        'generation' => 'abc123',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.generation', 'ABC123');

    $modelId = $response->json('data.id');

    $chassis = VehicleChassis::where('code', 'ABC123')->first();

    expect($chassis)->not->toBeNull();
    expect((int) $chassis->model_id)->toBe($modelId);

    $this->assertDatabaseHas('vehicle_chassis_model', [
        'chassis_id' => $chassis->id,
        'model_id' => $modelId,
    ]);
});

it('reuses existing chassis and associates model on update without duplicates', function () {
    $make = VehicleMake::create(['name' => 'Generation Update Make']);

    $modelA = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Generation Model A',
        'year_from' => 2010,
        'year_to' => 2016,
    ]);

    $modelB = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Generation Model B',
        'year_from' => 2017,
        'year_to' => 2024,
    ]);

    $existingChassis = VehicleChassis::create([
        'code' => 'ZX90',
        'name' => 'Existing Chassis',
        'model_id' => null,
    ]);

    $this->putJson("/api/v1/vehicle-models/{$modelB->id}", [
        'generation' => 'zx90',
    ])
        ->assertOk()
        ->assertJsonPath('data.generation', 'ZX90');

    $matchingChassis = VehicleChassis::where('code', 'ZX90')->get();

    expect($matchingChassis)->toHaveCount(1);
    expect((int) $existingChassis->fresh()->model_id)->toBe($modelB->id);

    $this->assertDatabaseHas('vehicle_chassis_model', [
        'chassis_id' => $existingChassis->id,
        'model_id' => $modelB->id,
    ]);

    // The previous model remains unaffected unless explicitly associated.
    $this->assertDatabaseMissing('vehicle_chassis_model', [
        'chassis_id' => $existingChassis->id,
        'model_id' => $modelA->id,
    ]);
});

it('creates Toyota Corolla E120 2000-2007 with chassis and matching years', function () {
    $make = VehicleMake::create(['name' => 'Toyota']);

    $response = $this->postJson('/api/v1/vehicle-models', [
        'make_id' => $make->id,
        'name' => 'Corolla',
        'year_from' => 2000,
        'year_to' => 2007,
        'generation' => 'E120',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.generation', 'E120')
        ->assertJsonPath('data.name', 'Corolla');

    $modelId = $response->json('data.id');

    $chassis = VehicleChassis::where('code', 'E120')->first();

    expect($chassis)->not->toBeNull();
    expect((int) $chassis->model_id)->toBe($modelId);
    expect($chassis->year_from)->toBe('2000');
    expect($chassis->year_to)->toBe('2007');

    $this->assertDatabaseHas('vehicle_chassis_model', [
        'chassis_id' => $chassis->id,
        'model_id' => $modelId,
    ]);
});

it('propagates updated model years to owned chassis', function () {
    $make = VehicleMake::create(['name' => 'Hyundai']);

    $response = $this->postJson('/api/v1/vehicle-models', [
        'make_id' => $make->id,
        'name' => 'Accent',
        'year_from' => 2012,
        'year_to' => 2017,
        'generation' => 'RB',
    ]);

    $response->assertCreated();
    $modelId = $response->json('data.id');

    $chassis = VehicleChassis::where('code', 'RB')->first();
    expect($chassis->year_from)->toBe('2012');
    expect($chassis->year_to)->toBe('2017');

    // Update the model's years
    $this->putJson("/api/v1/vehicle-models/{$modelId}", [
        'year_from' => 2011,
        'year_to' => 2018,
        'generation' => 'RB',
    ])->assertOk();

    $chassis->refresh();
    expect($chassis->year_from)->toBe('2011');
    expect($chassis->year_to)->toBe('2018');
});

it('includes chassis relation in model API responses', function () {
    $make = VehicleMake::create(['name' => 'Nissan']);

    $response = $this->postJson('/api/v1/vehicle-models', [
        'make_id' => $make->id,
        'name' => 'Sentra',
        'year_from' => 2013,
        'year_to' => 2019,
        'generation' => 'B17',
    ]);

    $response->assertCreated();
    $modelId = $response->json('data.id');

    // Show endpoint
    $this->getJson("/api/v1/vehicle-models/{$modelId}")
        ->assertOk()
        ->assertJsonStructure(['data' => ['chassis']]);

    // byMake endpoint
    $this->getJson("/api/v1/vehicle-makes/{$make->id}/models")
        ->assertOk()
        ->assertJsonStructure(['data' => [['chassis']]]);
});

it('rejects contradictory years when chassis belongs to another model', function () {
    $make = VehicleMake::create(['name' => 'Toyota']);

    // Create Corolla E120 2000-2007 — this creates chassis E120 owned by this model
    $this->postJson('/api/v1/vehicle-models', [
        'make_id' => $make->id,
        'name' => 'Corolla',
        'year_from' => 2000,
        'year_to' => 2007,
        'generation' => 'E120',
    ])->assertCreated();

    // Try to create another model with E120 but years that don't overlap at all
    $this->postJson('/api/v1/vehicle-models', [
        'make_id' => $make->id,
        'name' => 'Corolla Sedan',
        'year_from' => 2015,
        'year_to' => 2026,
        'generation' => 'E120',
    ])->assertUnprocessable();
});

it('rejects chassis_id and generation that point to different chassis', function () {
    $make = VehicleMake::create(['name' => 'Honda']);

    $chassisA = VehicleChassis::create(['code' => 'FK7', 'name' => 'Civic FK7', 'model_id' => null]);

    // chassis_id=FK7 but generation=FC1 — contradiction
    $this->postJson('/api/v1/vehicle-models', [
        'make_id' => $make->id,
        'name' => 'Civic',
        'year_from' => 2016,
        'year_to' => 2021,
        'generation' => 'FC1',
        'chassis_id' => $chassisA->id,
    ])->assertUnprocessable();
});

it('accepts explicit chassis_id and syncs generation field', function () {
    $make = VehicleMake::create(['name' => 'Mazda']);

    $chassis = VehicleChassis::create([
        'code' => 'BM',
        'name' => 'Mazda3 BM',
        'model_id' => null,
    ]);

    $response = $this->postJson('/api/v1/vehicle-models', [
        'make_id' => $make->id,
        'name' => 'Mazda3',
        'year_from' => 2014,
        'year_to' => 2018,
        'chassis_id' => $chassis->id,
    ]);

    $response->assertCreated();
    $modelId = $response->json('data.id');

    // Generation should be synced from chassis code
    $model = VehicleModel::find($modelId);
    expect($model->generation)->toBe('BM');

    // Chassis should be claimed by this model
    $chassis->refresh();
    expect((int) $chassis->model_id)->toBe($modelId);
    expect($chassis->year_from)->toBe('2014');
    expect($chassis->year_to)->toBe('2018');
});

it('allows overlapping years when chassis belongs to another model', function () {
    $make = VehicleMake::create(['name' => 'Nissan']);

    // Create Sentra B17 2013-2019
    $this->postJson('/api/v1/vehicle-models', [
        'make_id' => $make->id,
        'name' => 'Sentra',
        'year_from' => 2013,
        'year_to' => 2019,
        'generation' => 'B17',
    ])->assertCreated();

    // Create another model with B17 but overlapping years — should pass
    $this->postJson('/api/v1/vehicle-models', [
        'make_id' => $make->id,
        'name' => 'Sentra SR',
        'year_from' => 2015,
        'year_to' => 2018,
        'generation' => 'B17',
    ])->assertCreated();
});

it('rejects contradictory years even when chassis has no owner', function () {
    $make = VehicleMake::create(['name' => 'Ford']);

    // Create an unclaimed chassis with years
    VehicleChassis::create([
        'code' => 'CD539',
        'name' => 'Focus CD539',
        'model_id' => null,
        'year_from' => '2011',
        'year_to' => '2018',
    ]);

    // Try to claim it with contradictory years — should fail
    $this->postJson('/api/v1/vehicle-models', [
        'make_id' => $make->id,
        'name' => 'Focus',
        'year_from' => 2020,
        'year_to' => 2025,
        'generation' => 'CD539',
    ])->assertUnprocessable();
});

it('syncs chassis years when only year_from or year_to changes on update', function () {
    $make = VehicleMake::create(['name' => 'Kia']);

    $response = $this->postJson('/api/v1/vehicle-models', [
        'make_id' => $make->id,
        'name' => 'Rio',
        'year_from' => 2012,
        'year_to' => 2017,
        'generation' => 'UB',
    ]);

    $response->assertCreated();
    $modelId = $response->json('data.id');

    $chassis = VehicleChassis::where('code', 'UB')->first();
    expect($chassis->year_from)->toBe('2012');
    expect($chassis->year_to)->toBe('2017');

    // Update ONLY years (no generation or chassis_id in payload)
    $this->putJson("/api/v1/vehicle-models/{$modelId}", [
        'year_from' => 2011,
        'year_to' => 2018,
    ])->assertOk();

    $chassis->refresh();
    expect($chassis->year_from)->toBe('2011');
    expect($chassis->year_to)->toBe('2018');
});
