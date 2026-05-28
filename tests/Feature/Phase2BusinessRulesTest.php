<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesQuote;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $warehouse = Warehouse::create([
        'code' => 'W-P2',
        'name' => 'Warehouse Phase2',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $admin->warehouses()->attach($warehouse->id);

    $this->warehouse = $warehouse;
    $this->admin = $admin;
    $this->actingAs($admin);

    $this->product = Product::create([
        'name' => 'Test Product P2',
        'sku_base' => 'P2-BASE',
        'type' => 'simple',
        'status' => 'active',
    ]);

    $brand = Brand::create([
        'name' => 'Phase 2 Brand',
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'brand_id' => $brand->id,
        'sku' => 'SKU-P2-001',
        'name' => 'Variant P2',
    ]);

    $this->product->pricing()->create([
        'base_price' => 100.00,
        'calculated_price' => 107.00,
        'msrp' => 120.00,
        'cost' => 50.00,
        'margin_percentage' => 50,
        'tax_rate' => 7,
        'sale_tax_rate' => 7,
    ]);

    $this->variant->pricing()->create([
        'variant_id' => $this->variant->id,
        'base_price' => 100.00,
        'calculated_price' => 107.00,
        'msrp' => 120.00,
        'cost' => 50.00,
        'margin_percentage' => 50,
        'tax_rate' => 7,
        'sale_tax_rate' => 7,
    ]);
});

it('confirmed sale with zero stock returns 201, creates order, inventory stays at 0, no negative movement, returns warnings', function () {
    ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 0,
        'quantity_reserved' => 0,
    ]);

    $response = $this->postJson('/api/v1/sales-orders', [
        'warehouse_id' => $this->warehouse->id,
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'items' => [
            [
                'product_id' => $this->product->id,
                'variant_id' => $this->variant->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 5,
                'unit_price' => 100.00,
                'tax_percent' => 7,
            ],
        ],
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('success', true);

    $inventory = ProductInventory::first();
    expect($inventory->quantity_available)->toBe(0);

    $movementCount = \App\Models\InventoryMovement::where('product_id', $this->product->id)
        ->where('variant_id', $this->variant->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->count();
    expect($movementCount)->toBe(0);

    $order = SalesOrder::first();
    expect($order)->not->toBeNull();
    expect($order->status)->toBe('completed');
    expect($order->items()->first()->quantity)->toBe(5);
});

it('sale with partial stock deducts only available, leaves inventory at 0 and warns of shortage', function () {
    ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 3,
        'quantity_reserved' => 0,
    ]);

    $response = $this->postJson('/api/v1/sales-orders', [
        'warehouse_id' => $this->warehouse->id,
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'items' => [
            [
                'product_id' => $this->product->id,
                'variant_id' => $this->variant->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 5,
                'unit_price' => 100.00,
                'tax_percent' => 7,
            ],
        ],
    ]);

    $response->assertStatus(201);

    $inventory = ProductInventory::first();
    expect($inventory->quantity_available)->toBe(0);

    $movement = \App\Models\InventoryMovement::where('product_id', $this->product->id)
        ->where('variant_id', $this->variant->id)
        ->first();
    expect($movement)->not->toBeNull();
    expect($movement->quantity)->toBe(3);

    $order = SalesOrder::first();
    expect($order->items()->first()->quantity)->toBe(5);
});

it('quote with require_stock_for_quotes=true and zero stock succeeds with warnings', function () {
    Setting::set('require_stock_for_quotes', 'true', 'boolean', 'pos');

    ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 0,
        'quantity_reserved' => 0,
    ]);

    $response = $this->postJson('/api/v1/sales-quotes', [
        'warehouse_id' => $this->warehouse->id,
        'items' => [
            [
                'product_id' => $this->product->id,
                'variant_id' => $this->variant->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 3,
                'unit_price' => 100.00,
                'tax_percent' => 7,
            ],
        ],
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('success', true);

    $quote = SalesQuote::first();
    expect($quote)->not->toBeNull();
    expect($quote->items()->first()->quantity)->toBe(3);
});

it('tax_percent omitted applies store_tax_rate', function () {
    Setting::set('store_tax_rate', '10', 'number', 'pos');

    ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 10,
        'quantity_reserved' => 0,
    ]);

    $response = $this->postJson('/api/v1/sales-orders', [
        'warehouse_id' => $this->warehouse->id,
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'items' => [
            [
                'product_id' => $this->product->id,
                'variant_id' => $this->variant->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 1,
                'unit_price' => 100.00,
            ],
        ],
    ]);

    $response->assertStatus(201);

    $order = SalesOrder::first();
    expect($order->items()->first()->tax_percent)->toBe(10);
});

it('tax_percent zero is preserved as exempt', function () {
    ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 10,
        'quantity_reserved' => 0,
    ]);

    $response = $this->postJson('/api/v1/sales-orders', [
        'warehouse_id' => $this->warehouse->id,
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'items' => [
            [
                'product_id' => $this->product->id,
                'variant_id' => $this->variant->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 1,
                'unit_price' => 100.00,
                'tax_percent' => 0,
            ],
        ],
    ]);

    $response->assertStatus(201);

    $order = SalesOrder::first();
    expect($order->items()->first()->tax_percent)->toBe(0);
});

it('POS quote creation does not block on insufficient stock', function () {
    ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 2,
        'quantity_reserved' => 0,
    ]);

    $response = $this->postJson('/api/v1/sales-quotes/from-pos', [
        'warehouse_id' => $this->warehouse->id,
        'items' => [
            [
                'product_id' => $this->product->id,
                'variant_id' => $this->variant->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 10,
                'unit_price' => 100.00,
                'tax_percent' => 7,
            ],
        ],
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('success', true);

    $quote = SalesQuote::first();
    expect($quote)->not->toBeNull();
    expect($quote->items()->first()->quantity)->toBe(10);
});

it('sales order confirm returns warnings for stock shortage', function () {
    ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 2,
        'quantity_reserved' => 0,
    ]);

    $response = $this->postJson('/api/v1/sales-orders', [
        'warehouse_id' => $this->warehouse->id,
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'items' => [
            [
                'product_id' => $this->product->id,
                'variant_id' => $this->variant->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 5,
                'unit_price' => 100.00,
                'tax_percent' => 7,
            ],
        ],
    ]);

    $response->assertStatus(201);

    $warnings = $response->json('warnings');
    expect($warnings)->not->toBeNull();
    expect(count($warnings))->toBeGreaterThan(0);
    expect($warnings[0]['type'])->toBe('stock_shortage');
    expect($warnings[0]['missing_quantity'])->toBe(3);
});

it('tax_percent falls back to default 7 when store_tax_rate not set', function () {
    ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 10,
        'quantity_reserved' => 0,
    ]);

    Setting::where('key', 'store_tax_rate')->delete();

    $response = $this->postJson('/api/v1/sales-orders', [
        'warehouse_id' => $this->warehouse->id,
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'items' => [
            [
                'product_id' => $this->product->id,
                'variant_id' => $this->variant->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 1,
                'unit_price' => 100.00,
            ],
        ],
    ]);

    $response->assertStatus(201);

    $order = SalesOrder::first();
    expect($order->items()->first()->tax_percent)->toBe(7);
});

it('cancel sale with zero stock keeps inventory at 0 and does not create artificial stock', function () {
    ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 0,
        'quantity_reserved' => 0,
    ]);

    $response = $this->postJson('/api/v1/sales-orders', [
        'warehouse_id' => $this->warehouse->id,
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'items' => [
            [
                'product_id' => $this->product->id,
                'variant_id' => $this->variant->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 5,
                'unit_price' => 100.00,
                'tax_percent' => 7,
            ],
        ],
    ]);

    $response->assertStatus(201);

    $order = SalesOrder::first();
    expect($order)->not->toBeNull();

    $cancelResponse = $this->postJson("/api/v1/sales-orders/{$order->id}/cancel");
    $cancelResponse->assertSuccessful();

    $inventory = ProductInventory::first();
    expect($inventory->quantity_available)->toBe(0);

    $inMovements = \App\Models\InventoryMovement::where('product_id', $this->product->id)
        ->where('variant_id', $this->variant->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->where('movement_type', 'in')
        ->count();
    expect($inMovements)->toBe(0);
});

it('cancel sale with partial stock only restores actually deducted amount', function () {
    ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 3,
        'quantity_reserved' => 0,
    ]);

    $response = $this->postJson('/api/v1/sales-orders', [
        'warehouse_id' => $this->warehouse->id,
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'items' => [
            [
                'product_id' => $this->product->id,
                'variant_id' => $this->variant->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 5,
                'unit_price' => 100.00,
                'tax_percent' => 7,
            ],
        ],
    ]);

    $response->assertStatus(201);

    $inventoryAfterSale = ProductInventory::first();
    expect($inventoryAfterSale->quantity_available)->toBe(0);

    $order = SalesOrder::first();

    $cancelResponse = $this->postJson("/api/v1/sales-orders/{$order->id}/cancel");
    $cancelResponse->assertSuccessful();

    $inventoryAfterCancel = ProductInventory::first();
    expect($inventoryAfterCancel->quantity_available)->toBe(3);

    $inMovement = \App\Models\InventoryMovement::where('product_id', $this->product->id)
        ->where('variant_id', $this->variant->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->where('movement_type', 'in')
        ->first();
    expect($inMovement)->not->toBeNull();
    expect($inMovement->quantity)->toBe(3);
});

it('cancel sale with duplicate item lines restores deducted stock only once', function () {
    ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 3,
        'quantity_reserved' => 0,
    ]);

    $response = $this->postJson('/api/v1/sales-orders', [
        'warehouse_id' => $this->warehouse->id,
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'items' => [
            [
                'product_id' => $this->product->id,
                'variant_id' => $this->variant->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 2,
                'unit_price' => 100.00,
                'tax_percent' => 7,
            ],
            [
                'product_id' => $this->product->id,
                'variant_id' => $this->variant->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 4,
                'unit_price' => 100.00,
                'tax_percent' => 7,
            ],
        ],
    ]);

    $response->assertStatus(201);

    $inventoryAfterSale = ProductInventory::first();
    expect($inventoryAfterSale->quantity_available)->toBe(0);

    $outMovementQuantity = \App\Models\InventoryMovement::where('product_id', $this->product->id)
        ->where('variant_id', $this->variant->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->where('movement_type', 'out')
        ->sum('quantity');
    expect((int) $outMovementQuantity)->toBe(3);

    $order = SalesOrder::first();

    $cancelResponse = $this->postJson("/api/v1/sales-orders/{$order->id}/cancel");
    $cancelResponse->assertSuccessful();

    $inventoryAfterCancel = ProductInventory::first();
    expect($inventoryAfterCancel->quantity_available)->toBe(3);

    $inMovements = \App\Models\InventoryMovement::where('product_id', $this->product->id)
        ->where('variant_id', $this->variant->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->where('movement_type', 'in');

    expect($inMovements->count())->toBe(1);
    expect((int) $inMovements->sum('quantity'))->toBe(3);
});

it('explicit confirm endpoint on draft order returns warnings for stock shortage', function () {
    ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 2,
        'quantity_reserved' => 0,
    ]);

    $createResponse = $this->postJson('/api/v1/sales-orders', [
        'warehouse_id' => $this->warehouse->id,
        'status' => 'draft',
        'items' => [
            [
                'product_id' => $this->product->id,
                'variant_id' => $this->variant->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 10,
                'unit_price' => 100.00,
                'tax_percent' => 7,
            ],
        ],
    ]);

    $createResponse->assertStatus(201);

    $warnings = $createResponse->json('warnings');
    expect($warnings)->not->toBeNull();
    expect(count($warnings))->toBeGreaterThan(0);
    expect($warnings[0]['type'])->toBe('stock_shortage');
    expect($warnings[0]['missing_quantity'])->toBe(8);

    $order = SalesOrder::first();
    $confirmResponse = $this->postJson("/api/v1/sales-orders/{$order->id}/confirm");

    $confirmResponse->assertSuccessful();

    $confirmWarnings = $confirmResponse->json('warnings');
    expect($confirmWarnings)->not->toBeNull();
    expect(count($confirmWarnings))->toBeGreaterThan(0);
    expect($confirmWarnings[0]['type'])->toBe('stock_shortage');
});

it('quote creation with require_stock_for_quotes=true returns warnings array', function () {
    Setting::set('require_stock_for_quotes', 'true', 'boolean', 'pos');

    ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 1,
        'quantity_reserved' => 0,
    ]);

    $response = $this->postJson('/api/v1/sales-quotes', [
        'warehouse_id' => $this->warehouse->id,
        'items' => [
            [
                'product_id' => $this->product->id,
                'variant_id' => $this->variant->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 5,
                'unit_price' => 100.00,
                'tax_percent' => 7,
            ],
        ],
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('success', true);

    $warnings = $response->json('warnings');
    expect($warnings)->not->toBeNull();
    expect(count($warnings))->toBeGreaterThan(0);
    expect($warnings[0]['type'])->toBe('stock_shortage');
    expect($warnings[0]['missing_quantity'])->toBe(4);
});

it('POS quote from-pos returns warnings for stock shortage', function () {
    ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 1,
        'quantity_reserved' => 0,
    ]);

    $response = $this->postJson('/api/v1/sales-quotes/from-pos', [
        'warehouse_id' => $this->warehouse->id,
        'items' => [
            [
                'product_id' => $this->product->id,
                'variant_id' => $this->variant->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 10,
                'unit_price' => 100.00,
                'tax_percent' => 7,
            ],
        ],
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('success', true);

    $warnings = $response->json('warnings');
    expect($warnings)->not->toBeNull();
    expect(count($warnings))->toBeGreaterThan(0);
    expect($warnings[0]['type'])->toBe('stock_shortage');
});
