<?php

use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesQuote;
use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->warehouse = Warehouse::create([
        'code' => 'W-QUOTE',
        'name' => 'Warehouse Quote Flow',
    ]);

    $this->seller = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $this->warehouse->id,
        'is_active' => true,
    ]);
    $this->seller->warehouses()->attach($this->warehouse->id);

    $this->actingAs($this->seller);

    $this->product = Product::create([
        'name' => 'Quote Flow Product',
        'type' => 'simple',
        'status' => 'active',
        'sku_base' => 'QF-PRODUCT',
    ]);

    $this->brand = Brand::create([
        'name' => 'Quote Flow Brand',
        'status' => 'active',
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'brand_id' => $this->brand->id,
        'sku' => 'QF-001',
        'name' => 'Quote Flow Variant',
    ]);
});

it('sends quote to warehouse queue without marking it converted', function () {
    ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 1,
        'quantity_reserved' => 0,
    ]);

    $quote = SalesQuote::create([
        'customer_id' => null,
        'user_id' => $this->seller->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'sent',
    ]);

    $quote->items()->create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 5,
        'unit_price' => 100.00,
        'tax_percent' => 7,
    ]);

    $response = $this->postJson("/api/v1/sales-quotes/{$quote->id}/send-to-deposit");

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('warnings.0.type', 'stock_shortage')
        ->assertJsonPath('warnings.0.missing_quantity', 4);

    $quote->refresh();
    expect($quote->status)->toBe('sent_to_deposit');
    expect($quote->converted_order_id)->not->toBeNull();

    $order = SalesOrder::with('items')->find($quote->converted_order_id);
    expect($order)->not->toBeNull();
    expect($order->status)->toBe('pending');
    expect($order->sent_to_warehouse_at)->not->toBeNull();
    expect($order->prepared_at)->toBeNull();
    expect($order->items)->toHaveCount(1);
    expect($order->items->first()->warehouse_id)->toBe($this->warehouse->id);

    $this->withHeader('X-Warehouse-Id', $this->warehouse->id)
        ->getJson('/api/v1/sales-orders/pending-at-warehouse')
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('data.0.id', $order->id);
});

it('rejects quotes that are already in the warehouse queue', function () {
    $quote = SalesQuote::create([
        'customer_id' => null,
        'user_id' => $this->seller->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'sent_to_deposit',
    ]);

    $quote->items()->create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 1,
        'unit_price' => 100.00,
        'tax_percent' => 7,
    ]);

    $this->postJson("/api/v1/sales-quotes/{$quote->id}/send-to-deposit")
        ->assertUnprocessable();

    expect(SalesOrder::count())->toBe(0);
});

it('validates the quote customer vehicle and copies it to the resulting sale', function () {
    $customer = Customer::query()->create(['code' => 'C-Q-1', 'name' => 'Cliente Cotización']);
    $otherCustomer = Customer::query()->create(['code' => 'C-Q-2', 'name' => 'Otro Cliente']);
    $make = VehicleMake::query()->create(['name' => 'Honda']);
    $model = VehicleModel::query()->create([
        'make_id' => $make->id,
        'name' => 'Civic',
        'year_from' => 2012,
        'year_to' => 2026,
    ]);
    $engine = VehicleEngine::query()->create([
        'make_id' => $make->id,
        'model_id' => $model->id,
        'engine_code' => 'R18',
    ]);
    $engine->models()->syncWithoutDetaching([$model->id]);
    $vehicle = CustomerVehicle::query()->create([
        'customer_id' => $customer->id,
        'vehicle_model_id' => $model->id,
        'vehicle_engine_id' => $engine->id,
        'status' => 'active',
    ]);

    $payload = [
        'customer_id' => $otherCustomer->id,
        'customer_vehicle_id' => $vehicle->id,
        'warehouse_id' => $this->warehouse->id,
        'items' => [[
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 1,
            'unit_price' => 100,
        ]],
    ];

    $this->postJson('/api/v1/sales-quotes', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['customer_vehicle_id']);

    $payload['customer_id'] = $customer->id;
    $quoteId = $this->postJson('/api/v1/sales-quotes', $payload)
        ->assertCreated()
        ->assertJsonPath('data.customer_vehicle_id', $vehicle->id)
        ->json('data.id');

    $this->putJson("/api/v1/sales-quotes/{$quoteId}", ['status' => 'sent'])->assertOk();
    $this->postJson("/api/v1/sales-quotes/{$quoteId}/send-to-deposit")->assertCreated();

    expect(SalesQuote::query()->findOrFail($quoteId)->convertedOrder?->customer_vehicle_id)
        ->toBe($vehicle->id);
});
