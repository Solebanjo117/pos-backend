<?php

use App\Models\Brand;
use App\Models\CashierQueueEntry;
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

it('keeps a fixed monetary discount exact when sending a quote to deposit', function () {
    $payload = [
        'warehouse_id' => $this->warehouse->id,
        'items' => [[
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 2,
            'unit_price' => 50,
            'discount_percent' => 0,
            'discount_amount' => 12.34,
            'tax_percent' => 7,
            'is_custom_line' => false,
        ]],
    ];

    $quoteId = $this->postJson('/api/v1/sales-quotes/from-pos', $payload)
        ->assertCreated()
        ->assertJsonPath('data.items.0.discount_amount', '12.34')
        ->assertJsonPath('data.items.0.subtotal', '87.66')
        ->assertJsonPath('data.items.0.tax_amount', '6.14')
        ->assertJsonPath('data.items.0.total', '93.80')
        ->json('data.id');

    $response = $this->postJson("/api/v1/sales-quotes/{$quoteId}/send-to-deposit")
        ->assertCreated();

    $order = SalesOrder::query()->with('items')->findOrFail($response->json('data.id'));

    expect((float) $order->items->first()->discount_amount)->toBe(12.34)
        ->and((float) $order->items->first()->subtotal)->toBe(87.66)
        ->and((float) $order->items->first()->tax_amount)->toBe(6.14)
        ->and((float) $order->items->first()->total)->toBe(93.80)
        ->and((float) $order->total)->toBe(93.80);
});

it('updates a fixed discount without converting it back to a percentage', function () {
    $quote = SalesQuote::create([
        'user_id' => $this->seller->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'draft',
    ]);

    $payload = [
        'warehouse_id' => $this->warehouse->id,
        'items' => [[
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 3,
            'unit_price' => 50,
            'discount_amount' => 12.34,
            'tax_percent' => 7,
            'is_custom_line' => false,
        ]],
    ];

    $this->putJson("/api/v1/sales-quotes/{$quote->id}", $payload)
        ->assertOk()
        ->assertJsonPath('data.items.0.discount_amount', '12.34')
        ->assertJsonPath('data.items.0.subtotal', '137.66')
        ->assertJsonPath('data.items.0.tax_amount', '9.64')
        ->assertJsonPath('data.items.0.total', '147.30');
});

it('rejects a fixed discount greater than the gross line amount', function () {
    $this->postJson('/api/v1/sales-quotes/from-pos', [
        'warehouse_id' => $this->warehouse->id,
        'items' => [[
            'product_id' => null,
            'variant_id' => null,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 1,
            'unit_price' => 10,
            'discount_amount' => 10.01,
            'tax_percent' => 7,
            'is_custom_line' => true,
            'custom_description' => 'Servicio inválido',
        ]],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('items.0.discount_amount');
});

it('queues the exact discounted quote total at cashier with its daily folio', function () {
    $quoteId = $this->postJson('/api/v1/sales-quotes/from-pos', [
        'warehouse_id' => $this->warehouse->id,
        'items' => [[
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 1,
            'unit_price' => 40,
            'discount_amount' => 5,
            'tax_percent' => 7,
        ]],
    ])->assertCreated()
        ->assertJsonPath('data.total', '37.45')
        ->json('data.id');

    $this->postJson("/api/v1/sales-quotes/{$quoteId}/send-to-cashier")
        ->assertOk()
        ->assertJsonPath('data.status', 'sent_to_cashier');

    $quote = SalesQuote::query()->findOrFail($quoteId);
    $entry = CashierQueueEntry::query()
        ->where('source_type', SalesQuote::class)
        ->where('source_id', $quoteId)
        ->firstOrFail();

    expect($quote->quote_number)->toMatch('/^\d{12}$/')
        ->and((float) $entry->estimated_amount)->toBe(37.45)
        ->and($entry->metadata['document_number'])->toBe($quote->quote_number);
});
