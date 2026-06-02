<?php

use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->warehouse = Warehouse::create([
        'code' => 'WO-OPS',
        'name' => 'Taller Principal',
    ]);

    $this->user = User::factory()->create([
        'role' => 'seller',
        'is_active' => true,
        'current_warehouse_id' => $this->warehouse->id,
    ]);
    $this->user->warehouses()->attach($this->warehouse->id);

    $this->mechanic = User::factory()->create([
        'role' => 'assistant',
        'is_active' => true,
        'current_warehouse_id' => $this->warehouse->id,
    ]);
    $this->mechanic->warehouses()->attach($this->warehouse->id);

    $this->customer = Customer::create([
        'code' => 'C-WO-001',
        'name' => 'Cliente Taller',
        'phone' => '6000-0000',
        'status' => 'active',
    ]);

    $make = VehicleMake::create(['name' => 'Toyota']);
    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Hilux',
        'year_from' => 2018,
        'year_to' => 2024,
    ]);
    $engine = VehicleEngine::create([
        'model_id' => $model->id,
        'make_id' => $make->id,
        'engine_code' => '2GD',
        'displacement' => '2.4',
        'fuel_type' => 'diesel',
    ]);

    $this->vehicle = CustomerVehicle::create([
        'customer_id' => $this->customer->id,
        'vehicle_model_id' => $model->id,
        'vehicle_engine_id' => $engine->id,
        'vin' => 'VINWORKORDER001',
        'label' => 'Hilux taller',
        'status' => 'active',
    ]);

    $brand = Brand::create(['name' => 'WO Parts', 'status' => 'active']);
    $this->product = Product::create([
        'sku_base' => 'WO-PART',
        'name' => 'Amortiguador delantero',
        'status' => 'active',
    ]);
    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'brand_id' => $brand->id,
        'sku' => 'WO-PART-V',
        'status' => 'active',
        'is_default' => true,
    ]);
    ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 8,
        'quantity_reserved' => 0,
    ]);
});

it('creates a work order with customer vehicle and mechanic using the operational contract', function (): void {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/work-orders', [
            'customer_id' => $this->customer->id,
            'customer_vehicle_id' => $this->vehicle->id,
            'warehouse_id' => $this->warehouse->id,
            'assigned_to' => $this->mechanic->id,
            'vehicle_description' => 'Cambio de suspension frontal',
            'labour_cost' => 45.50,
            'estimated_delivery_at' => now()->addDay()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'variant_id' => $this->variant->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 2,
                'unit_price' => 35,
            ]],
        ])
        ->assertCreated()
        ->assertJsonPath('data.customer_id', $this->customer->id)
        ->assertJsonPath('data.customer_vehicle_id', $this->vehicle->id)
        ->assertJsonPath('data.assigned_to', $this->mechanic->id)
        ->assertJsonPath('data.customer_vehicle.id', $this->vehicle->id)
        ->assertJsonPath('data.customer_vehicle.model.make.name', 'Toyota')
        ->assertJsonPath('data.assignee.name', $this->mechanic->name)
        ->assertJsonPath('data.total_parts', 70)
        ->assertJsonPath('data.total_labour', 45.5)
        ->assertJsonPath('data.total_amount', 115.5)
        ->assertJsonPath('data.available_transitions', ['in_progress', 'cancelled']);

    expect(ProductInventory::query()
        ->where('product_id', $this->product->id)
        ->where('variant_id', $this->variant->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->value('quantity_available'))->toBe(6);

    $this->actingAs($this->user)
        ->getJson('/api/v1/work-orders/'.$response->json('data.id'))
        ->assertOk()
        ->assertJsonPath('customer.id', $this->customer->id)
        ->assertJsonPath('customer_vehicle.id', $this->vehicle->id)
        ->assertJsonPath('total_amount', 115.5);
});

it('filters work orders and returns additive summary and meta blocks', function (): void {
    WorkOrder::create([
        'customer_id' => $this->customer->id,
        'customer_vehicle_id' => $this->vehicle->id,
        'warehouse_id' => $this->warehouse->id,
        'assigned_to' => $this->mechanic->id,
        'created_by' => $this->user->id,
        'status' => 'pending',
        'vehicle_description' => 'Hilux suspension',
        'estimated_delivery_at' => now()->subDay(),
    ]);

    WorkOrder::create([
        'warehouse_id' => $this->warehouse->id,
        'created_by' => $this->user->id,
        'status' => 'delivered',
        'vehicle_description' => 'Orden rapida',
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/v1/work-orders?customer_id='.$this->customer->id.'&assigned_to='.$this->mechanic->id.'&search=Hilux')
        ->assertOk()
        ->assertJsonStructure([
            'data',
            'current_page',
            'total',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            'summary' => [
                'total',
                'pending',
                'in_progress',
                'done',
                'delivered',
                'cancelled',
                'overdue',
            ],
        ])
        ->assertJsonPath('total', 1)
        ->assertJsonPath('summary.total', 1)
        ->assertJsonPath('summary.pending', 1)
        ->assertJsonPath('summary.overdue', 1);
});

it('rejects a customer vehicle that belongs to another customer', function (): void {
    $otherCustomer = Customer::create([
        'code' => 'C-WO-002',
        'name' => 'Otro Cliente',
        'status' => 'active',
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/v1/work-orders', [
            'customer_id' => $otherCustomer->id,
            'customer_vehicle_id' => $this->vehicle->id,
            'warehouse_id' => $this->warehouse->id,
            'vehicle_description' => 'Revision',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['customer_vehicle_id']);
});

it('keeps compatibility with quick work orders that only have vehicle description', function (): void {
    $this->actingAs($this->user)
        ->postJson('/api/v1/work-orders', [
            'warehouse_id' => $this->warehouse->id,
            'vehicle_description' => 'Nissan Frontier sin registro',
        ])
        ->assertCreated()
        ->assertJsonPath('data.customer_id', null)
        ->assertJsonPath('data.customer_vehicle_id', null)
        ->assertJsonPath('data.vehicle_description', 'Nissan Frontier sin registro');
});
