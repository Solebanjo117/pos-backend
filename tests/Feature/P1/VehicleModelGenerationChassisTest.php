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
