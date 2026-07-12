<?php

use App\Models\Brand;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesQuote;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->warehouse = Warehouse::create(['code' => 'W-CASH', 'name' => 'Caja']);
    $this->cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $this->warehouse->id,
        'is_active' => true,
    ]);
    $this->cashier->warehouses()->attach($this->warehouse->id);
    $this->seller = User::factory()->create(['role' => 'seller', 'is_active' => true]);
    $this->product = Product::create(['name' => 'Producto Caja', 'type' => 'simple', 'status' => 'active', 'sku_base' => 'CAJA-1']);
    $brand = Brand::create(['name' => 'Marca Caja', 'status' => 'active']);
    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'brand_id' => $brand->id,
        'sku' => 'CAJA-V1',
        'name' => 'Variante Caja',
    ]);
    ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 10,
        'quantity_reserved' => 0,
    ]);
    $this->quote = SalesQuote::create([
        'user_id' => $this->seller->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'sent_to_cashier',
        'sent_to_cashier_at' => now(),
    ]);
    $this->quoteItem = $this->quote->items()->create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 1,
        'unit_price' => 100,
        'discount_percent' => 10,
        'tax_percent' => 7,
    ]);
    $this->actingAs($this->cashier);
});

it('rejects manual cashier sales and commercial mutations', function (): void {
    $this->getJson('/api/v1/sales-orders')->assertForbidden();
    $this->getJson('/api/v1/sales-quotes')->assertForbidden();
    $this->getJson('/api/v1/reports/z-report?date=2026-07-12')->assertOk();

    $this->postJson('/api/v1/sales-orders', [
        'items' => [[
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 1,
            'unit_price' => 100,
        ]],
    ])->assertUnprocessable();

    $order = SalesOrder::create(['user_id' => $this->seller->id, 'date' => now(), 'status' => 'draft']);
    $this->putJson("/api/v1/sales-orders/{$order->id}", [])->assertForbidden();
    $this->postJson("/api/v1/sales-orders/{$order->id}/items", [])->assertForbidden();
    $this->postJson("/api/v1/sales-quotes/{$this->quote->id}/convert", [])->assertForbidden();
});

it('opens and checks out a queued quote without trusting commercial fields', function (): void {
    PosSession::create([
        'user_id' => $this->cashier->id,
        'warehouse_id' => $this->warehouse->id,
        'opened_at' => now(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $this->postJson("/api/v1/sales-quotes/{$this->quote->id}/open-at-cashier")
        ->assertOk()
        ->assertJsonPath('data.id', $this->quote->id);
    $this->assertDatabaseHas('activity_log', [
        'description' => 'cashier_opened',
        'causer_id' => $this->cashier->id,
    ]);

    $payload = [
        'source_quote_id' => $this->quote->id,
        'status' => 'confirmed',
        'payments' => [['method' => 'cash', 'amount' => 96.30]],
        'items' => [[
            'source_quote_item_id' => $this->quoteItem->id,
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 99,
            'unit_price' => 1,
            'discount_percent' => 0,
            'tax_percent' => 7,
        ]],
    ];

    $first = $this->postJson('/api/v1/sales-orders', $payload)->assertCreated();
    $orderId = $first->json('data.id');
    $order = SalesOrder::with(['items', 'payments'])->findOrFail($orderId);
    expect($order->user_id)->toBe($this->cashier->id)
        ->and($order->items)->toHaveCount(1)
        ->and($order->items->first()->quantity)->toBe(1)
        ->and((float) $order->items->first()->unit_price)->toBe(100.0)
        ->and((float) $order->items->first()->discount_percent)->toBe(10.0)
        ->and($order->payments)->toHaveCount(1);

    $this->postJson('/api/v1/sales-orders', $payload)
        ->assertCreated()
        ->assertJsonPath('data.id', $orderId);

    expect(SalesOrder::count())->toBe(1)
        ->and($order->fresh()->payments()->count())->toBe(1)
        ->and($this->quote->fresh()->status)->toBe('collected')
        ->and($this->quote->fresh()->converted_order_id)->toBe($orderId);
    $this->assertDatabaseHas('activity_log', [
        'description' => 'cashier_collected',
        'causer_id' => $this->cashier->id,
    ]);
});

it('requires an open cashier session for the quote warehouse', function (): void {
    $this->postJson("/api/v1/sales-quotes/{$this->quote->id}/open-at-cashier")
        ->assertUnprocessable();
});
