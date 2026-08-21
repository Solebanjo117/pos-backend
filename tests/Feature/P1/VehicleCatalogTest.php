<?php

use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\VehicleVin;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
    $this->actingAs($this->admin);
});

function vehicleCatalogPair(string $suffix = ''): array
{
    $make = VehicleMake::create(['name' => 'Marca catálogo '.$suffix]);
    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo catálogo '.$suffix,
        'year_from' => 2020,
        'year_to' => 2026,
        'generation' => 'GEN-'.$suffix,
    ]);
    $engine = VehicleEngine::create([
        'make_id' => $make->id,
        'engine_code' => 'ENG-'.$suffix,
        'displacement' => '2.0L',
        'fuel_type' => 'Gasoline',
    ]);

    return [$make, $model, $engine];
}

it('counts pivot-only configurations once in the centralized summary', function (): void {
    [$make, $model, $engine] = vehicleCatalogPair('SUMMARY');
    $engine->models()->attach($model->id);

    $this->getJson('/api/v1/vehicle-catalog/summary')
        ->assertOk()
        ->assertJsonPath('data.makes', 1)
        ->assertJsonPath('data.models', 1)
        ->assertJsonPath('data.engines', 1)
        ->assertJsonPath('data.vehicles', 1);

    $this->getJson('/api/v1/vehicle-makes')
        ->assertOk()
        ->assertJsonPath('data.0.models_count', 1)
        ->assertJsonPath('data.0.engines_count', 1)
        ->assertJsonPath('data.0.vehicles_count', 1);

    $this->getJson('/api/v1/vehicle-models')
        ->assertOk()
        ->assertJsonPath('data.0.engines_count', 1)
        ->assertJsonPath('data.0.vehicles_count', 1);

    $this->getJson('/api/v1/vehicle-engines')
        ->assertOk()
        ->assertJsonPath('data.0.vehicles_count', 1);

    expect($make->exists)->toBeTrue();
});

it('derives an engine make from legacy and pivot models without double counting', function (): void {
    [$make, $model, $directEngine] = vehicleCatalogPair('DERIVED-MAKE');
    $directEngine->delete();
    $engine = VehicleEngine::create([
        'make_id' => null,
        'model_id' => $model->id,
        'engine_code' => 'ENG-DERIVED-MAKE',
    ]);
    $engine->models()->attach($model->id);

    $this->getJson('/api/v1/vehicle-makes')
        ->assertOk()
        ->assertJsonPath('data.0.id', $make->id)
        ->assertJsonPath('data.0.engines_count', 1);
});

it('builds PostgreSQL safe aggregate queries for vehicle catalog counts', function (): void {
    [, $model, $engine] = vehicleCatalogPair('SQL');
    $engine->models()->attach($model->id);
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $sql = mb_strtolower(ltrim($query->sql));
        if (str_contains($sql, 'vehicle_pairs') && str_contains($sql, 'count(*) as aggregate')) {
            $queries[] = $sql;
        }
    });

    $this->getJson('/api/v1/vehicle-makes')->assertOk();
    $this->getJson('/api/v1/vehicle-models')->assertOk();
    $this->getJson('/api/v1/vehicle-engines')->assertOk();

    expect($queries)->toHaveCount(3);
    foreach ($queries as $query) {
        expect($query)->not->toMatch('/^select\s+["`]?vehicle_pairs["`]?\.["`]?model_id/');
    }
});

it('searches catalog tabs with tokens distributed across related fields', function (): void {
    [$make, $model, $engine] = vehicleCatalogPair('SEARCH');
    $make->update(['name' => 'Toyota']);
    $model->update(['name' => 'Corolla']);
    $engine->update(['engine_code' => '2ZR FE']);
    $engine->models()->attach($model->id);

    $this->getJson('/api/v1/vehicle-models?search=Toyota%20Corolla')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/vehicle-engines?search=Toyota%202ZR')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/vehicle-configurations?search=Toyota%20Corolla%202ZR')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('sorts models and engines alphabetically by make name', function (): void {
    vehicleCatalogPair('ZULU')[0]->update(['name' => 'Zulu']);
    vehicleCatalogPair('ALFA')[0]->update(['name' => 'Alfa']);

    $this->getJson('/api/v1/vehicle-models?sort=make&direction=asc')
        ->assertOk()
        ->assertJsonPath('data.0.make.name', 'Alfa');
    $this->getJson('/api/v1/vehicle-engines?sort=make&direction=asc')
        ->assertOk()
        ->assertJsonPath('data.0.make.name', 'Alfa');
});

it('protects active relationships when changing a model make or engine models', function (): void {
    [, $model, $engine] = vehicleCatalogPair('PROTECTED-PAIR');
    $engine->models()->attach($model->id);
    VehicleVin::create([
        'model_id' => $model->id,
        'engine_id' => $engine->id,
        'vin' => 'PROTECTED-VIN',
    ]);
    $otherMake = VehicleMake::create(['name' => 'Otra marca']);

    $this->putJson("/api/v1/vehicle-models/{$model->id}", [
        'make_id' => $otherMake->id,
        'name' => $model->name,
        'year_from' => $model->year_from,
        'year_to' => $model->year_to,
        'generation' => $model->generation,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('make_id');

    $this->putJson("/api/v1/vehicle-engines/{$engine->id}", [
        'model_ids' => [],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('model_ids');

    expect($model->fresh()->make_id)->not->toBe($otherMake->id)
        ->and($engine->models()->whereKey($model->id)->exists())->toBeTrue()
        ->and(VehicleVin::where('vin', 'PROTECTED-VIN')->exists())->toBeTrue();
});

it('creates a model engine configuration and normalizes its vins', function (): void {
    [, $model, $engine] = vehicleCatalogPair('CREATE');

    $this->postJson('/api/v1/vehicle-configurations', [
        'model_id' => $model->id,
        'engine_id' => $engine->id,
        'vins' => [
            ['vin' => ' vin-abc-001 ', 'notes' => 'Principal'],
            ['vin' => 'vin-abc-002'],
        ],
    ])->assertCreated();

    $this->assertDatabaseHas('vehicle_engine_model', [
        'model_id' => $model->id,
        'engine_id' => $engine->id,
    ]);
    $this->assertDatabaseHas('vehicle_vins', [
        'model_id' => $model->id,
        'engine_id' => $engine->id,
        'vin' => 'VIN-ABC-001',
    ]);

    $this->getJson('/api/v1/vehicle-configurations?search=abc-002')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.vins_count', 2);
});

it('rejects duplicate configurations duplicate vins and cross-make pairs', function (): void {
    [, $model, $engine] = vehicleCatalogPair('DUPLICATE');
    [, $otherModel] = vehicleCatalogPair('OTHER');

    $this->postJson('/api/v1/vehicle-configurations', [
        'model_id' => $model->id,
        'engine_id' => $engine->id,
        'vins' => [['vin' => 'VIN-DUP'], ['vin' => 'vin-dup']],
    ])->assertUnprocessable()->assertJsonValidationErrors('vins');

    $this->postJson('/api/v1/vehicle-configurations', [
        'model_id' => $otherModel->id,
        'engine_id' => $engine->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('engine_id');

    $engine->models()->attach($model->id);
    $this->postJson('/api/v1/vehicle-configurations', [
        'model_id' => $model->id,
        'engine_id' => $engine->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('engine_id');
});

it('marks and protects customer linked vins and configurations', function (): void {
    [, $model, $engine] = vehicleCatalogPair('CUSTOMER');
    $engine->models()->attach($model->id);
    $vin = VehicleVin::create([
        'model_id' => $model->id,
        'engine_id' => $engine->id,
        'vin' => 'CUSTOMER-VIN',
    ]);
    $customer = Customer::create([
        'code' => 'CAT-CUSTOMER',
        'name' => 'Cliente catálogo',
    ]);
    CustomerVehicle::create([
        'customer_id' => $customer->id,
        'vehicle_model_id' => $model->id,
        'vehicle_engine_id' => $engine->id,
        'vin' => 'CUSTOMER-VIN',
        'status' => 'active',
    ]);

    $this->getJson('/api/v1/vehicle-configurations')
        ->assertOk()
        ->assertJsonPath('data.0.vins.0.id', $vin->id)
        ->assertJsonPath('data.0.vins.0.is_customer_linked', true)
        ->assertJsonPath('data.0.can_delete', false);

    $this->deleteJson("/api/v1/vehicle-configurations/{$model->id}/{$engine->id}")
        ->assertUnprocessable();
    $this->assertDatabaseHas('vehicle_vins', ['id' => $vin->id]);
});

it('performs bulk deletion atomically when one selected row is protected', function (): void {
    [$freeMake] = vehicleCatalogPair('FREE');
    [, $model, $engine] = vehicleCatalogPair('BLOCKED');
    $engine->models()->attach($model->id);

    $this->postJson('/api/v1/vehicle-catalog/bulk-delete', [
        'entity' => 'makes',
        'ids' => [$freeMake->id, $model->make_id],
    ])->assertUnprocessable();

    $this->assertDatabaseHas('vehicle_makes', ['id' => $freeMake->id]);
    $this->assertDatabaseHas('vehicle_makes', ['id' => $model->make_id]);
});

it('exposes modification capabilities only to administrators and managers', function (): void {
    [$make] = vehicleCatalogPair('ROLES');
    $viewer = User::factory()->create([
        'role' => 'seller',
        'is_active' => true,
    ]);

    $this->actingAs($viewer)
        ->getJson('/api/v1/vehicle-makes')
        ->assertOk()
        ->assertJsonPath('data.0.can_edit', false)
        ->assertJsonPath('data.0.can_delete', false);

    $this->postJson('/api/v1/vehicle-makes', ['name' => 'No autorizada'])
        ->assertForbidden();

    $this->getJson('/api/v1/vehicle-catalog/export')->assertOk();
    expect($make->exists)->toBeTrue();
});
