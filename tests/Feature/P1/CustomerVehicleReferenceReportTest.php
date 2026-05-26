<?php

use App\Models\Customer;
use App\Models\SalesOrder;
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

function customerVehicleCatalogScope(array $overrides = []): array
{
    $make = VehicleMake::create([
        'name' => $overrides['make_name'] ?? 'Toyota '.str()->random(6),
    ]);

    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => $overrides['model_name'] ?? 'YARIS',
        'year_from' => $overrides['year_from'] ?? 2006,
        'year_to' => $overrides['year_to'] ?? 2013,
        'generation' => $overrides['generation'] ?? 'P90',
    ]);

    $chassis = VehicleChassis::create([
        'model_id' => $model->id,
        'code' => $overrides['chassis_code'] ?? 'P90',
        'name' => $overrides['chassis_name'] ?? 'P90',
    ]);
    $model->chassis()->syncWithoutDetaching([$chassis->id]);

    $engine = VehicleEngine::create([
        'make_id' => $make->id,
        'model_id' => $model->id,
        'engine_code' => $overrides['engine_code'] ?? '1NZ',
        'displacement' => $overrides['displacement'] ?? '1.5L',
        'fuel_type' => $overrides['fuel_type'] ?? 'Gasolina',
    ]);
    $engine->models()->syncWithoutDetaching([$model->id]);

    return [$model, $chassis, $engine];
}

it('stores customer vehicles and links their vins to the selected engine', function (): void {
    $customer = Customer::create([
        'code' => 'CV-'.fake()->unique()->randomNumber(5),
        'name' => 'Cliente con Yaris',
    ]);
    [$model, $chassis, $engine] = customerVehicleCatalogScope();

    $this->postJson("/api/v1/customers/{$customer->id}/vehicles", [
        'vehicle_model_id' => $model->id,
        'vehicle_chassis_id' => $chassis->id,
        'vehicle_engine_id' => $engine->id,
        'vin' => '1234asdf',
        'label' => 'Yaris diario',
        'notes' => 'Color gris',
    ])
        ->assertCreated()
        ->assertJsonPath('data.vin', '1234ASDF')
        ->assertJsonPath('data.label', 'Yaris diario')
        ->assertJsonPath('data.vehicle_model_id', $model->id);

    $this->getJson("/api/v1/customers/{$customer->id}/vehicles")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.vin', '1234ASDF')
        ->assertJsonPath('data.0.make.name', $model->make->name)
        ->assertJsonPath('data.0.model.name', $model->name);

    $this->assertDatabaseHas('customer_vehicles', [
        'customer_id' => $customer->id,
        'vehicle_model_id' => $model->id,
        'vehicle_chassis_id' => $chassis->id,
        'vehicle_engine_id' => $engine->id,
        'vin' => '1234ASDF',
    ]);
    $this->assertDatabaseHas('vehicle_vins', [
        'model_id' => $model->id,
        'engine_id' => $engine->id,
        'chassis_id' => null,
        'vin' => '1234ASDF',
        'notes' => 'Cliente con Yaris',
    ]);
});

it('stores customer vehicles without chassis when the selected model has no chassis', function (): void {
    $customer = Customer::create([
        'code' => 'CV-'.fake()->unique()->randomNumber(5),
        'name' => 'Cliente con modelo sin chasis',
    ]);
    $make = VehicleMake::create(['name' => 'Chevrolet']);
    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'AVEO',
        'year_from' => 2008,
        'year_to' => 2013,
        'generation' => 'P10',
    ]);
    $engine = VehicleEngine::create([
        'make_id' => $make->id,
        'model_id' => $model->id,
        'engine_code' => 'F14D4',
        'displacement' => '1.4L',
        'fuel_type' => 'Gasolina',
    ]);
    $engine->models()->syncWithoutDetaching([$model->id]);

    $this->postJson("/api/v1/customers/{$customer->id}/vehicles", [
        'vehicle_model_id' => $model->id,
        'vehicle_chassis_id' => null,
        'vehicle_engine_id' => $engine->id,
        'vin' => 'NOCHASIS123',
    ])
        ->assertCreated()
        ->assertJsonPath('data.vehicle_chassis_id', null)
        ->assertJsonPath('data.vehicle_model_id', $model->id);

    $this->assertDatabaseHas('customer_vehicles', [
        'customer_id' => $customer->id,
        'vehicle_model_id' => $model->id,
        'vehicle_chassis_id' => null,
        'vehicle_engine_id' => $engine->id,
        'vin' => 'NOCHASIS123',
    ]);
    $this->assertDatabaseHas('vehicle_vins', [
        'model_id' => $model->id,
        'engine_id' => $engine->id,
        'chassis_id' => null,
        'vin' => 'NOCHASIS123',
        'notes' => 'Cliente con modelo sin chasis',
    ]);
});

it('moves the linked catalog vin when a customer vehicle changes vin or engine', function (): void {
    $customer = Customer::create([
        'code' => 'CV-'.fake()->unique()->randomNumber(5),
        'name' => 'Cliente sincronizado',
    ]);
    [$model, $chassis, $firstEngine] = customerVehicleCatalogScope();
    $secondEngine = VehicleEngine::create([
        'make_id' => $model->make_id,
        'model_id' => $model->id,
        'engine_code' => '2NZ',
        'displacement' => '1.3L',
        'fuel_type' => 'Gasolina',
    ]);
    $secondEngine->models()->syncWithoutDetaching([$model->id]);

    $createResponse = $this->postJson("/api/v1/customers/{$customer->id}/vehicles", [
        'vehicle_model_id' => $model->id,
        'vehicle_chassis_id' => $chassis->id,
        'vehicle_engine_id' => $firstEngine->id,
        'vin' => 'OLDVIN001',
    ])->assertCreated();

    $vehicleId = $createResponse->json('data.id');

    $this->putJson("/api/v1/customers/{$customer->id}/vehicles/{$vehicleId}", [
        'vehicle_model_id' => $model->id,
        'vehicle_chassis_id' => $chassis->id,
        'vehicle_engine_id' => $secondEngine->id,
        'vin' => 'NEWVIN002',
        'status' => 'active',
    ])->assertOk();

    $this->assertDatabaseMissing('vehicle_vins', [
        'model_id' => $model->id,
        'engine_id' => $firstEngine->id,
        'vin' => 'OLDVIN001',
    ]);
    $this->assertDatabaseHas('vehicle_vins', [
        'model_id' => $model->id,
        'engine_id' => $secondEngine->id,
        'chassis_id' => null,
        'vin' => 'NEWVIN002',
        'notes' => 'Cliente sincronizado',
    ]);
});

it('removes the linked catalog vin when the last active customer vehicle is deactivated', function (): void {
    $customer = Customer::create([
        'code' => 'CV-'.fake()->unique()->randomNumber(5),
        'name' => 'Cliente a desactivar',
    ]);
    [$model, $chassis, $engine] = customerVehicleCatalogScope();

    $createResponse = $this->postJson("/api/v1/customers/{$customer->id}/vehicles", [
        'vehicle_model_id' => $model->id,
        'vehicle_chassis_id' => $chassis->id,
        'vehicle_engine_id' => $engine->id,
        'vin' => 'REMOVEVIN',
    ])->assertCreated();

    $vehicleId = $createResponse->json('data.id');

    $this->deleteJson("/api/v1/customers/{$customer->id}/vehicles/{$vehicleId}")
        ->assertOk();

    $this->assertDatabaseMissing('vehicle_vins', [
        'model_id' => $model->id,
        'engine_id' => $engine->id,
        'vin' => 'REMOVEVIN',
    ]);
});

it('keeps a shared linked catalog vin while another active customer vehicle uses it', function (): void {
    $firstCustomer = Customer::create([
        'code' => 'CV-'.fake()->unique()->randomNumber(5),
        'name' => 'Primer cliente',
    ]);
    $secondCustomer = Customer::create([
        'code' => 'CV-'.fake()->unique()->randomNumber(5),
        'name' => 'Segundo cliente',
    ]);
    [$model, $chassis, $engine] = customerVehicleCatalogScope();

    $firstResponse = $this->postJson("/api/v1/customers/{$firstCustomer->id}/vehicles", [
        'vehicle_model_id' => $model->id,
        'vehicle_chassis_id' => $chassis->id,
        'vehicle_engine_id' => $engine->id,
        'vin' => 'SHAREDVIN',
    ])->assertCreated();
    $this->postJson("/api/v1/customers/{$secondCustomer->id}/vehicles", [
        'vehicle_model_id' => $model->id,
        'vehicle_chassis_id' => $chassis->id,
        'vehicle_engine_id' => $engine->id,
        'vin' => 'SHAREDVIN',
    ])->assertCreated();

    $this->deleteJson("/api/v1/customers/{$firstCustomer->id}/vehicles/{$firstResponse->json('data.id')}")
        ->assertOk();

    $this->assertDatabaseHas('vehicle_vins', [
        'model_id' => $model->id,
        'engine_id' => $engine->id,
        'vin' => 'SHAREDVIN',
        'notes' => 'Segundo cliente',
    ]);
});

it('reuses existing catalog vins and replaces their notes with the customer name', function (): void {
    $customer = Customer::create([
        'code' => 'CV-'.fake()->unique()->randomNumber(5),
        'name' => 'Cliente actual',
    ]);
    [$model, $chassis, $engine] = customerVehicleCatalogScope();

    VehicleVin::create([
        'model_id' => $model->id,
        'engine_id' => $engine->id,
        'chassis_id' => null,
        'vin' => 'EXISTINGVIN',
        'notes' => 'Texto anterior',
    ]);

    $this->postJson("/api/v1/customers/{$customer->id}/vehicles", [
        'vehicle_model_id' => $model->id,
        'vehicle_chassis_id' => $chassis->id,
        'vehicle_engine_id' => $engine->id,
        'vin' => 'EXISTINGVIN',
    ])->assertCreated();

    expect(VehicleVin::query()
        ->where('model_id', $model->id)
        ->where('engine_id', $engine->id)
        ->where('vin', 'EXISTINGVIN')
        ->count())->toBe(1);

    $this->assertDatabaseHas('vehicle_vins', [
        'model_id' => $model->id,
        'engine_id' => $engine->id,
        'vin' => 'EXISTINGVIN',
        'notes' => 'Cliente actual',
    ]);
});

it('rejects customer vehicles with mismatched model chassis or engine', function (): void {
    $customer = Customer::create([
        'code' => 'CV-'.fake()->unique()->randomNumber(5),
        'name' => 'Cliente con carro invalido',
    ]);
    [$model, $chassis] = customerVehicleCatalogScope();
    [, , $otherEngine] = customerVehicleCatalogScope(['model_name' => 'COROLLA', 'engine_code' => '2ZR']);

    $this->postJson("/api/v1/customers/{$customer->id}/vehicles", [
        'vehicle_model_id' => $model->id,
        'vehicle_chassis_id' => $chassis->id,
        'vehicle_engine_id' => $otherEngine->id,
        'vin' => 'BADVIN',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['vehicle_engine_id']);
});

it('stores sales order vehicle and referring customer only when the vehicle belongs to the billed customer', function (): void {
    $billedCustomer = Customer::create([
        'code' => 'CUST-'.fake()->unique()->randomNumber(5),
        'name' => 'Cliente final',
    ]);
    $otherCustomer = Customer::create([
        'code' => 'CUST-'.fake()->unique()->randomNumber(5),
        'name' => 'Otro cliente',
    ]);
    $mechanic = Customer::create([
        'code' => 'MEC-'.fake()->unique()->randomNumber(5),
        'name' => 'Mecanico 1',
    ]);
    [$model, $chassis, $engine] = customerVehicleCatalogScope();

    $vehicle = \App\Models\CustomerVehicle::create([
        'customer_id' => $billedCustomer->id,
        'vehicle_model_id' => $model->id,
        'vehicle_chassis_id' => $chassis->id,
        'vehicle_engine_id' => $engine->id,
        'vin' => 'OWNERVIN',
        'status' => 'active',
    ]);

    $this->postJson('/api/v1/sales-orders', [
        'customer_id' => $otherCustomer->id,
        'customer_vehicle_id' => $vehicle->id,
        'referred_by_customer_id' => $mechanic->id,
        'status' => 'draft',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['customer_vehicle_id']);

    $this->postJson('/api/v1/sales-orders', [
        'customer_id' => $billedCustomer->id,
        'customer_vehicle_id' => $vehicle->id,
        'referred_by_customer_id' => $mechanic->id,
        'status' => 'draft',
    ])
        ->assertCreated()
        ->assertJsonPath('data.customer_vehicle_id', $vehicle->id)
        ->assertJsonPath('data.referred_by_customer_id', $mechanic->id);
});

it('reports direct referenced and combined customer revenue separately', function (): void {
    $seller = User::factory()->create(['role' => 'seller', 'is_active' => true]);
    $mechanic = Customer::create(['code' => 'MEC-001', 'name' => 'Mecanico 1']);
    $clientOne = Customer::create(['code' => 'CLI-001', 'name' => 'Cliente 1']);
    $clientTwo = Customer::create(['code' => 'CLI-002', 'name' => 'Cliente 2']);

    SalesOrder::create([
        'order_number' => 'REF-001',
        'customer_id' => $mechanic->id,
        'user_id' => $seller->id,
        'date' => '2026-05-01',
        'status' => 'completed',
        'total' => 10,
        'payment_status' => 'paid',
    ]);
    SalesOrder::create([
        'order_number' => 'REF-002',
        'customer_id' => $clientOne->id,
        'referred_by_customer_id' => $mechanic->id,
        'user_id' => $seller->id,
        'date' => '2026-05-02',
        'status' => 'completed',
        'total' => 5,
        'payment_status' => 'paid',
    ]);
    SalesOrder::create([
        'order_number' => 'REF-003',
        'customer_id' => $clientTwo->id,
        'referred_by_customer_id' => $mechanic->id,
        'user_id' => $seller->id,
        'date' => '2026-05-03',
        'status' => 'completed',
        'total' => 6,
        'payment_status' => 'paid',
    ]);

    $response = $this->getJson('/api/v1/reports/customer-references?date_from=2026-05-01&date_to=2026-05-31');

    $response->assertOk();

    $rows = collect($response->json('data'));
    $mechanicRow = $rows->firstWhere('customer_id', $mechanic->id);
    $clientOneRow = $rows->firstWhere('customer_id', $clientOne->id);
    $clientTwoRow = $rows->firstWhere('customer_id', $clientTwo->id);

    expect((float) $mechanicRow['direct_revenue'])->toBe(10.0)
        ->and((float) $mechanicRow['referred_revenue'])->toBe(11.0)
        ->and((float) $mechanicRow['combined_revenue'])->toBe(21.0)
        ->and($mechanicRow['orders_count'])->toBe(1)
        ->and($mechanicRow['referred_orders_count'])->toBe(2)
        ->and((float) $clientOneRow['direct_revenue'])->toBe(5.0)
        ->and((float) $clientOneRow['referred_revenue'])->toBe(0.0)
        ->and((float) $clientTwoRow['direct_revenue'])->toBe(6.0)
        ->and((float) $clientTwoRow['referred_revenue'])->toBe(0.0);
});
