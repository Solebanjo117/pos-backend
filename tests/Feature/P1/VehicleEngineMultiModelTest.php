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

    $modelA = VehicleModel::create([
        'make_id' => $makeA->id,
        'name' => 'Modelo Motor A',
        'year_from' => 2014,
        'year_to' => 2020,
    ]);

    $modelB = VehicleModel::create([
        'make_id' => $makeA->id,
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
        ->assertJsonPath('data.make_id', $makeA->id)
        ->assertJsonPath('data.model_id', $modelA->id)
        ->assertJsonPath('data.model_ids.0', $modelA->id)
        ->assertJsonPath('data.model_ids.1', $modelB->id);

    $engineId = $response->json('data.id');

    $this->assertDatabaseHas('vehicle_engines', [
        'id' => $engineId,
        'engine_code' => 'MM-EN-001',
        'make_id' => $makeA->id,
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
        'make_id' => $make->id,
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
        ->assertJsonPath('data.make_id', $make->id)
        ->assertJsonPath('data.model_id', $model->id)
        ->assertJsonPath('data.model_ids.0', $model->id);

    $engineId = $response->json('data.id');

    $this->assertDatabaseHas('vehicle_engine_model', [
        'engine_id' => $engineId,
        'model_id' => $model->id,
    ]);
});

it('stores engine with explicit vehicle make and no model association', function () {
    $make = VehicleMake::create(['name' => 'Marca Motor Catalogo']);

    $response = $this->postJson('/api/v1/vehicle-engines', [
        'make_id' => $make->id,
        'engine_code' => 'CAT-MAKE-001',
        'displacement' => '1.6L',
        'fuel_type' => 'Gasoline',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.make_id', $make->id)
        ->assertJsonPath('data.make.id', $make->id)
        ->assertJsonPath('data.model_id', null);

    $this->assertDatabaseHas('vehicle_engines', [
        'make_id' => $make->id,
        'engine_code' => 'CAT-MAKE-001',
        'model_id' => null,
    ]);
});

it('rejects model associations that do not belong to the selected make', function () {
    $makeA = VehicleMake::create(['name' => 'Marca Motor Scope A']);
    $makeB = VehicleMake::create(['name' => 'Marca Motor Scope B']);

    $modelB = VehicleModel::create([
        'make_id' => $makeB->id,
        'name' => 'Modelo Scope B',
        'year_from' => 2020,
        'year_to' => 2025,
    ]);

    $this->postJson('/api/v1/vehicle-engines', [
        'make_id' => $makeA->id,
        'engine_code' => 'MAKE-SCOPE-001',
        'fuel_type' => 'Gasoline',
        'model_ids' => [$modelB->id],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['model_ids']);
});

it('filters engines by explicit vehicle make', function () {
    $makeA = VehicleMake::create(['name' => 'Marca Filtro Motor A']);
    $makeB = VehicleMake::create(['name' => 'Marca Filtro Motor B']);

    VehicleEngine::create([
        'make_id' => $makeA->id,
        'engine_code' => 'FILTER-MAKE-A',
        'fuel_type' => 'Gasoline',
    ]);

    VehicleEngine::create([
        'make_id' => $makeB->id,
        'engine_code' => 'FILTER-MAKE-B',
        'fuel_type' => 'Gasoline',
    ]);

    $response = $this->getJson("/api/v1/vehicle-engines?make_id={$makeA->id}");

    $response->assertOk();
    $codes = collect($response->json('data'))->pluck('engine_code')->all();

    expect($codes)->toContain('FILTER-MAKE-A')
        ->and($codes)->not->toContain('FILTER-MAKE-B');
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
        'make_id' => $make->id,
        'model_id' => $modelA->id,
    ]);

    $engine->models()->sync([$modelA->id, $modelB->id]);

    $response = $this->getJson("/api/v1/vehicle-models/{$modelB->id}/engines");

    $response->assertOk();

    $returnedIds = collect($response->json('data'))->pluck('id')->all();
    expect($returnedIds)->toContain($engine->id);
});

it('rejects duplicate engine code for the same model', function () {
    $make = VehicleMake::create(['name' => 'Marca Duplicado Mismo Modelo']);

    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Duplicado Mismo Modelo',
        'year_from' => 2015,
        'year_to' => 2022,
    ]);

    $this->postJson('/api/v1/vehicle-engines', [
        'engine_code' => 'DUP-MODEL-01',
        'model_ids' => [$model->id],
        'fuel_type' => 'Gasoline',
    ])->assertCreated();

    $this->postJson('/api/v1/vehicle-engines', [
        'engine_code' => ' dup-model-01 ',
        'model_ids' => [$model->id],
        'fuel_type' => 'Gasoline',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['engine_code']);
});

it('rejects duplicate engine code for the same make even when models do not overlap', function () {
    $make = VehicleMake::create(['name' => 'Marca Duplicado Distinto Modelo']);

    $modelA = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Duplicado Distinto A',
        'year_from' => 2012,
        'year_to' => 2018,
    ]);

    $modelB = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Duplicado Distinto B',
        'year_from' => 2019,
        'year_to' => 2025,
    ]);

    $this->postJson('/api/v1/vehicle-engines', [
        'engine_code' => 'DUP-MODEL-02',
        'model_ids' => [$modelA->id],
        'fuel_type' => 'Diesel',
    ])->assertCreated();

    $this->postJson('/api/v1/vehicle-engines', [
        'engine_code' => 'DUP-MODEL-02',
        'model_ids' => [$modelB->id],
        'fuel_type' => 'Diesel',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['engine_code']);
});

it('rejects duplicate engine code when model associations overlap', function () {
    $make = VehicleMake::create(['name' => 'Marca Duplicado Traslape']);

    $modelA = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Traslape A',
        'year_from' => 2010,
        'year_to' => 2016,
    ]);

    $modelB = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Traslape B',
        'year_from' => 2017,
        'year_to' => 2021,
    ]);

    $modelC = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Traslape C',
        'year_from' => 2022,
        'year_to' => 2026,
    ]);

    $this->postJson('/api/v1/vehicle-engines', [
        'engine_code' => 'DUP-MODEL-03',
        'model_ids' => [$modelA->id, $modelB->id],
        'fuel_type' => 'Hybrid',
    ])->assertCreated();

    $this->postJson('/api/v1/vehicle-engines', [
        'engine_code' => 'DUP-MODEL-03',
        'model_ids' => [$modelB->id, $modelC->id],
        'fuel_type' => 'Hybrid',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['engine_code']);
});

it('rejects duplicate engine code for the same make without models', function () {
    $make = VehicleMake::create(['name' => 'Marca Motor Independiente']);

    $this->postJson('/api/v1/vehicle-engines', [
        'make_id' => $make->id,
        'engine_code' => 'INDEP-EN-001',
        'fuel_type' => 'Electric',
    ])->assertCreated();

    $this->postJson('/api/v1/vehicle-engines', [
        'make_id' => $make->id,
        'engine_code' => ' inDep-en-001 ',
        'fuel_type' => 'Electric',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['engine_code']);
});

it('accepts and collapses engine code with internal whitespace', function () {
    $make = VehicleMake::create(['name' => 'Marca Espacios Motor']);

    $response = $this->postJson('/api/v1/vehicle-engines', [
        'make_id' => $make->id,
        'engine_code' => "ENG   001\tA",
        'fuel_type' => 'Gasoline',
    ])->assertCreated();

    expect($response->json('data.engine_code'))->toBe('ENG 001 A');
});

it('rejects fuel type outside the closed catalog', function () {
    $make = VehicleMake::create(['name' => 'Marca Fuel Cerrado']);

    $this->postJson('/api/v1/vehicle-engines', [
        'make_id' => $make->id,
        'engine_code' => 'FUEL-OUT-001',
        'fuel_type' => 'Gasolina',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['fuel_type']);
});

it('returns 403 when a non admin or manager tries to create an engine', function () {
    $make = VehicleMake::create(['name' => 'Marca Rol Bloqueado']);

    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Rol Bloqueado',
        'year_from' => 2018,
        'year_to' => 2025,
    ]);

    $seller = User::factory()->create([
        'role' => 'seller',
        'is_active' => true,
    ]);

    $this->actingAs($seller);

    $this->postJson('/api/v1/vehicle-engines', [
        'engine_code' => 'ROLE-403-001',
        'model_ids' => [$model->id],
        'fuel_type' => 'Gasoline',
    ])->assertForbidden();
});

it('normalizes engine_code by trimming surrounding whitespace on store', function () {
    $make = VehicleMake::create(['name' => 'Marca Trim Motor']);

    $response = $this->postJson('/api/v1/vehicle-engines', [
        'make_id' => $make->id,
        'engine_code' => '  2ZR-FE  ',
        'fuel_type' => 'Gasoline',
    ])->assertCreated();

    expect($response->json('data.engine_code'))->toBe('2ZR-FE');
});

it('rejects fuel type with incorrect casing that does not match any catalog entry after normalization', function () {
    $make = VehicleMake::create(['name' => 'Marca Fuel Casing']);

    // 'Gasolina' is not in the closed catalog (Gasoline/Diesel/Hybrid/Electric)
    $this->postJson('/api/v1/vehicle-engines', [
        'make_id' => $make->id,
        'engine_code' => 'CASING-FUEL-01',
        'fuel_type' => 'Gasolina',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['fuel_type']);
});

it('rejects duplicate engine code when existing engine links to model only via pivot (model_id is null)', function () {
    $make = VehicleMake::create(['name' => 'Marca Pivot Only']);

    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Pivot Only',
        'year_from' => 2015,
        'year_to' => 2022,
    ]);

    // Create engine with no legacy model_id, associate only via pivot
    $engine = VehicleEngine::create([
        'engine_code' => 'PIVOT-ONLY-01',
        'fuel_type' => 'Gasoline',
        'make_id' => $make->id,
        'model_id' => null,
    ]);
    $engine->models()->sync([$model->id]);

    // Attempt to create another engine with the same code for the same model via model_ids
    $this->postJson('/api/v1/vehicle-engines', [
        'engine_code' => 'PIVOT-ONLY-01',
        'fuel_type' => 'Gasoline',
        'model_ids' => [$model->id],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['engine_code']);
});

it('ignores legacy singular model_id updates unless model_ids is explicitly sent', function () {
    $make = VehicleMake::create(['name' => 'Marca Legacy Update']);

    $modelA = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Legacy A',
        'year_from' => 2013,
        'year_to' => 2019,
    ]);

    $modelB = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Legacy B',
        'year_from' => 2020,
        'year_to' => 2024,
    ]);

    $modelC = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Modelo Legacy C',
        'year_from' => 2025,
        'year_to' => 2027,
    ]);

    $engine = VehicleEngine::create([
        'engine_code' => 'LEG-UPD-001',
        'displacement' => '2.0L',
        'fuel_type' => 'Gasoline',
        'make_id' => $make->id,
        'model_id' => $modelA->id,
    ]);

    $engine->models()->sync([$modelA->id, $modelB->id]);

    $response = $this->putJson("/api/v1/vehicle-engines/{$engine->id}", [
        'model_id' => $modelC->id,
        'fuel_type' => 'Diesel',
    ]);

    $response->assertOk();

    $returnedModelIds = collect($response->json('data.model_ids'))->sort()->values()->all();
    expect($returnedModelIds)->toBe(collect([$modelA->id, $modelB->id])->sort()->values()->all());

    $this->assertDatabaseHas('vehicle_engines', [
        'id' => $engine->id,
        'model_id' => $modelA->id,
        'fuel_type' => 'Diesel',
    ]);

    $this->assertDatabaseHas('vehicle_engine_model', [
        'engine_id' => $engine->id,
        'model_id' => $modelA->id,
    ]);

    $this->assertDatabaseHas('vehicle_engine_model', [
        'engine_id' => $engine->id,
        'model_id' => $modelB->id,
    ]);

    $this->assertDatabaseMissing('vehicle_engine_model', [
        'engine_id' => $engine->id,
        'model_id' => $modelC->id,
    ]);
});
